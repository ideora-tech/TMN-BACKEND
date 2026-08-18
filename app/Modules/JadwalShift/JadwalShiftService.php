<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use Illuminate\Support\Facades\DB;

class JadwalShiftService
{
    public function __construct(
        private readonly JadwalShiftRepositoryInterface $repo,
        private readonly \App\Modules\AlokasiArmada\AlokasiArmadaService $alokasiService,
        private readonly \App\Modules\Trip\Contracts\TripRepositoryInterface $tripRepo,
        private readonly \App\Modules\Cuti\Contracts\CutiRepositoryInterface $cutiRepo,
    ) {}

    public function list(string $idProyek, string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $rows = $this->repo->listByProyek($idProyek, $dari, $sampai);
        if ($rows === []) {
            return $rows;
        }

        $idSupirList = collect($rows)->pluck('id_supir')->unique()->values()->all();
        $tanggalAwal = (string) collect($rows)->min('tanggal');
        $tanggalAkhir = (string) collect($rows)->max('tanggal');
        $statusMap = $this->tripRepo->statusTripPerSupirTanggal($idProyek, $idSupirList, $tanggalAwal, $tanggalAkhir);
        $titikDropMap = $this->repo->listTitikDropOverrideUntukBanyak(array_column($rows, 'id_jadwal_shift'));

        foreach ($rows as $row) {
            $daftarTrip = $statusMap["{$row->id_supir}|{$row->tanggal}"] ?? [];
            $utama = $this->tripUtama($daftarTrip);
            $row->trips       = $daftarTrip;
            $row->status_trip = $utama['status'] ?? null;
            $row->id_trip     = $utama['id_trip'] ?? null;
            $row->titik_drop_override = $titikDropMap[$row->id_jadwal_shift] ?? [];
        }

        return $rows;
    }

    /**
     * Trip "utama" satu sel papan buat kompatibilitas kode lama yang masih
     * pakai status_trip/id_trip tunggal (mis. tombol Batal Trip/Selesaikan) —
     * trip yang masih berjalan menang, kalau tidak ada dipakai yang paling
     * baru dibuat. Daftar lengkap tetap tersedia lewat `trips`.
     *
     * @param array{status: 'berjalan'|'selesai', id_trip: string}[] $daftarTrip
     * @return array{status: 'berjalan'|'selesai', id_trip: string}|null
     */
    private function tripUtama(array $daftarTrip): ?array
    {
        if ($daftarTrip === []) {
            return null;
        }

        foreach (array_reverse($daftarTrip) as $trip) {
            if ($trip['status'] === 'berjalan') {
                return $trip;
            }
        }

        return $daftarTrip[array_key_last($daftarTrip)];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jadwal shift tidak ditemukan');
        }
        return $record;
    }

    /**
     * Batch assign: satu shift + rentang tanggal (tanggal..tanggal_sampai, opsional) + banyak supir.
     * Aturan per (supir, tanggal) — gagal per-item, bukan gagal total:
     * - wajib punya penugasan internal pending/aktif di proyek;
     * - maks 1 shift per tanggal GLOBAL lintas proyek (soft-delete aware) —
     *   tanggal yang bentrok dilewati dan dilaporkan, tanggal lain tetap terisi.
     */
    public function createBatch(array $data, string $idPerusahaan): array
    {
        if (!$this->repo->proyekMilikPerusahaan($data['id_proyek'], $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $mulai   = \Carbon\Carbon::parse($data['tanggal']);
        $selesai = \Carbon\Carbon::parse($data['tanggal_sampai'] ?? $data['tanggal']);

        if ($mulai->diffInDays($selesai) > 62) {
            abort(422, 'Rentang tanggal maksimal 62 hari');
        }

        $periode = [];
        for ($t = $mulai->copy(); $t->lte($selesai); $t->addDay()) {
            $periode[] = $t->toDateString();
        }

        return DB::transaction(function () use ($data, $periode) {
            $sukses = 0;
            $gagal  = [];
            $terjadwal = [];

            foreach (array_unique($data['supir']) as $idSupir) {
                if (!$this->repo->supirPunyaPenugasan($data['id_proyek'], $idSupir)) {
                    foreach ($periode as $tanggal) {
                        $gagal[] = ['id_supir' => $idSupir, 'tanggal' => $tanggal, 'alasan' => 'Supir tidak ter-assign ke proyek ini'];
                    }
                    continue;
                }

                foreach ($periode as $tanggal) {
                    $ada = $this->repo->findAktifBySupirTanggal($idSupir, $tanggal);
                    if ($ada !== null) {
                        $gagal[] = [
                            'id_supir' => $idSupir,
                            'tanggal'  => $tanggal,
                            'alasan'   => "Supir sudah dijadwalkan shift {$ada->shift_nama} (proyek {$ada->nama_proyek})",
                        ];
                        continue;
                    }

                    $this->repo->create([
                        'id_proyek' => $data['id_proyek'],
                        'id_shift'  => $data['id_shift'],
                        'id_supir'  => $idSupir,
                        'tanggal'   => $tanggal,
                    ]);
                    $terjadwal[] = ['id_supir' => $idSupir, 'tanggal' => $tanggal];
                    $sukses++;
                }
            }

            // Hitung ulang alokasi armada untuk rentang tanggal yang baru saja
            // berubah — bukan cuma pasangan baru, supaya supir LAIN di proyek
            // yang sama yang terdampak (mis. pemiliknya baru ikut dijadwalkan
            // di batch ini, atau baru bebas) ikut ter-update otomatis.
            $this->hitungUlangUntukTerjadwal($terjadwal, $data['id_proyek']);

            return ['sukses' => $sukses, 'gagal' => $gagal];
        });
    }

    public function templateData(string $idProyek, string $idPerusahaan, string $dari, string $sampai): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $mulai   = \Carbon\Carbon::parse($dari);
        $selesai = \Carbon\Carbon::parse($sampai);
        if ($mulai->diffInDays($selesai) > 62) {
            abort(422, 'Rentang tanggal maksimal 62 hari');
        }

        $tanggal = [];
        for ($t = $mulai->copy(); $t->lte($selesai); $t->addDay()) {
            $tanggal[] = $t->toDateString();
        }

        $periode = $mulai->isSameMonth($selesai, true)
            ? 'PERIODE ' . mb_strtoupper($mulai->locale('id')->translatedFormat('F Y'))
            : 'PERIODE ' . $mulai->format('d/m/Y') . ' - ' . $selesai->format('d/m/Y');

        return [
            'supir'       => $this->repo->supirTerdaftarDiProyek($idProyek),
            'tanggal'     => $tanggal,
            'nama_proyek' => $this->repo->namaProyek($idProyek) ?? 'JADWAL SHIFT SUPIR',
            'periode'     => $periode,
        ];
    }

    /**
     * Import matriks jadwal shift: baris = supir (No SIM + nama shift),
     * kolom ke-4 dst = tanggal, isi sel H = dijadwalkan, selain itu dilewati.
     * Gagal per-item (baris/tanggal bermasalah dilaporkan, sisanya tetap masuk);
     * konflik jadwal di proyek yang sama ditimpa (delete-insert, dilaporkan di
     * `ditimpa`), konflik lintas proyek tetap gagal,
     * lalu alokasi armada otomatis dijalankan untuk semua yang berhasil.
     */
    public function importMatriks(\Illuminate\Http\UploadedFile $file, string $idProyek, string $idPerusahaan): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Modules\JadwalShift\Imports\JadwalShiftImport(), $file)[0] ?? [];
        if (count($rows) < 2) {
            abort(422, 'File kosong atau tidak berisi baris data');
        }

        $barisHeader = null;
        foreach ($rows as $i => $row) {
            if (strtolower(trim((string) ($row[0] ?? ''))) === 'no sim') {
                $barisHeader = $i;
                break;
            }
        }
        if ($barisHeader === null) {
            abort(422, 'Baris header (kolom "No SIM") tidak ditemukan');
        }

        $tanggalKolom = [];
        foreach (array_slice($rows[$barisHeader], 3, null, true) as $kolom => $nilai) {
            $tanggal = $this->parseTanggalHeader($nilai);
            if ($tanggal !== null) {
                $tanggalKolom[$kolom] = $tanggal;
            }
        }
        if ($tanggalKolom === []) {
            abort(422, 'Kolom tanggal tidak ditemukan pada baris header (mulai kolom ke-4)');
        }

        return DB::transaction(function () use ($rows, $barisHeader, $tanggalKolom, $idProyek, $idPerusahaan) {
            $sukses    = 0;
            $ditimpa   = [];
            $gagal     = [];
            $terjadwal = [];

            foreach (array_slice($rows, $barisHeader + 1, null, true) as $idx => $row) {
                $barisKe   = $idx + 1;
                $noSim     = trim((string) ($row[0] ?? ''));
                $namaShift = trim((string) ($row[2] ?? ''));

                if ($noSim === '' && $namaShift === '') {
                    continue;
                }

                if ($namaShift === '') {
                    $adaH = false;
                    foreach (array_keys($tanggalKolom) as $kolom) {
                        if (strtoupper(trim((string) ($row[$kolom] ?? ''))) === 'H') {
                            $adaH = true;
                            break;
                        }
                    }
                    if (!$adaH) {
                        continue;
                    }
                }

                $supir = $this->repo->supirByNoSim($noSim, $idPerusahaan);
                if ($supir === null) {
                    $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Supir dengan No SIM '{$noSim}' tidak ditemukan"];
                    continue;
                }

                $shift = $this->repo->shiftByNama($namaShift, $idPerusahaan);
                if ($shift === null) {
                    $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Shift '{$namaShift}' tidak ditemukan di master shift"];
                    continue;
                }

                if (!$this->repo->supirPunyaPenugasan($idProyek, (string) $supir->id_supir)) {
                    $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => 'Supir tidak ter-assign ke proyek ini'];
                    continue;
                }

                foreach ($tanggalKolom as $kolom => $tanggal) {
                    if (strtoupper(trim((string) ($row[$kolom] ?? ''))) !== 'H') {
                        continue;
                    }

                    $ada = $this->repo->findAktifBySupirTanggal((string) $supir->id_supir, $tanggal);
                    if ($ada !== null) {
                        if ((string) $ada->id_proyek !== $idProyek) {
                            $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Tanggal {$tanggal}: sudah dijadwalkan shift {$ada->shift_nama} di proyek {$ada->nama_proyek}"];
                            continue;
                        }

                        if ((string) $ada->id_shift === (string) $shift->id_shift) {
                            $sukses++;
                            continue;
                        }

                        $statusTrip = $this->statusTripUntukJadwal($ada);
                        if ($statusTrip !== null) {
                            $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Tanggal {$tanggal}: trip supir " . $this->labelStatusTrip($statusTrip) . ' — jadwal tidak ditimpa'];
                            continue;
                        }

                        if ($tanggal === now()->toDateString()) {
                            $tripAktif = $this->tripRepo->findTripAktifUntukAktor(null, (string) $supir->id_supir, null, null);
                            if ($tripAktif !== null) {
                                $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Tanggal {$tanggal}: supir masih punya trip aktif — jadwal tidak ditimpa"];
                                continue;
                            }
                        }

                        $this->repo->delete($ada);
                        $this->alokasiService->hapusUntukJadwal((string) $supir->id_supir, $tanggal);
                        $this->repo->create([
                            'id_proyek' => $idProyek,
                            'id_shift'  => $shift->id_shift,
                            'id_supir'  => $supir->id_supir,
                            'tanggal'   => $tanggal,
                        ]);
                        $ditimpa[]   = ['baris' => $barisKe, 'no_sim' => $noSim, 'tanggal' => $tanggal, 'shift_lama' => $ada->shift_nama, 'shift_baru' => $shift->nama];
                        $terjadwal[] = ['id_supir' => (string) $supir->id_supir, 'tanggal' => $tanggal];
                        continue;
                    }

                    $this->repo->create([
                        'id_proyek' => $idProyek,
                        'id_shift'  => $shift->id_shift,
                        'id_supir'  => $supir->id_supir,
                        'tanggal'   => $tanggal,
                    ]);
                    $terjadwal[] = ['id_supir' => (string) $supir->id_supir, 'tanggal' => $tanggal];
                    $sukses++;
                }
            }

            $this->hitungUlangUntukTerjadwal($terjadwal, $idProyek);

            return ['sukses' => $sukses, 'ditimpa' => $ditimpa, 'gagal' => $gagal];
        });
    }

    /** @param array<int, array{id_supir: string, tanggal: string}> $terjadwal */
    private function hitungUlangUntukTerjadwal(array $terjadwal, string $idProyek): void
    {
        if ($terjadwal === []) {
            return;
        }
        $tanggalList = array_column($terjadwal, 'tanggal');
        $this->alokasiService->hitungUlangRentang($idProyek, min($tanggalList), max($tanggalList));
    }

    private function parseTanggalHeader(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }
        if (is_numeric($nilai)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $nilai)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return \Carbon\Carbon::parse(trim((string) $nilai))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function statusTripUntukJadwal(object $record): ?string
    {
        $tanggal = (string) $record->tanggal;
        $map = $this->tripRepo->statusTripPerSupirTanggal(
            (string) $record->id_proyek,
            [(string) $record->id_supir],
            $tanggal,
            $tanggal,
        );

        $utama = $this->tripUtama($map["{$record->id_supir}|{$tanggal}"] ?? []);
        return $utama['status'] ?? null;
    }

    private function labelStatusTrip(string $status): string
    {
        return $status === 'selesai' ? 'sudah selesai' : 'sedang berjalan';
    }

    public function updateShift(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);

        if (!$this->repo->proyekMilikPerusahaan((string) $record->id_proyek, $idPerusahaan)) {
            abort(404, 'Jadwal shift tidak ditemukan');
        }

        $statusTrip = $this->statusTripUntukJadwal($record);
        if ($statusTrip !== null) {
            abort(422, "Jadwal tanggal {$record->tanggal} tidak dapat diganti — trip supir pada tanggal ini " . $this->labelStatusTrip($statusTrip));
        }

        if (array_key_exists('id_supir_pengganti', $data) && $data['id_supir_pengganti'] !== null) {
            $idPengganti = (string) $data['id_supir_pengganti'];

            if ($idPengganti === (string) $record->id_supir) {
                abort(422, 'Supir pengganti tidak boleh sama dengan supir baris ini — kosongkan field untuk membatalkan override');
            }
            if ($this->cutiRepo->supirSedangCuti($idPengganti, (string) $record->tanggal)) {
                abort(422, 'Supir pengganti sedang cuti pada tanggal ini');
            }
            $bentrok = $this->repo->findAktifBySupirTanggal($idPengganti, (string) $record->tanggal);
            if ($bentrok !== null && (string) $bentrok->id_jadwal_shift !== (string) $record->id_jadwal_shift) {
                abort(422, "Supir pengganti sudah dijadwalkan shift {$bentrok->shift_nama} di proyek {$bentrok->nama_proyek} pada tanggal ini");
            }
        }

        $updated = $this->repo->updateShift($record, $data);

        if (array_key_exists('titik_drop_override', $data)) {
            if ($data['titik_drop_override'] === null) {
                $this->repo->syncTitikDropOverride((string) $record->id_jadwal_shift, []);
            } else {
                $this->repo->syncTitikDropOverride((string) $record->id_jadwal_shift, $data['titik_drop_override']);
            }
        }

        $this->alokasiService->alokasikan((string) $updated->id_supir, (string) $updated->tanggal, (string) $updated->id_proyek);
        $updated = $this->repo->findById((string) $record->id_jadwal_shift);
        $updated->titik_drop_override = $this->repo->listTitikDropOverride((string) $record->id_jadwal_shift);

        return $updated;
    }

    /**
     * Jadwal hari ini tidak boleh dihapus selama supirnya masih punya trip
     * aktif — menghapus jadwal memicu penghitungan ulang alokasi armada, dan
     * armada yang sedang dipakai trip aktif bisa keliru ditawarkan sebagai
     * "menganggur" ke supir lain (lihat AlokasiArmadaRepository::kandidatArmadaNganggur).
     */
    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id);

        if (!$this->repo->proyekMilikPerusahaan((string) $record->id_proyek, $idPerusahaan)) {
            abort(404, 'Jadwal shift tidak ditemukan');
        }

        $statusTrip = $this->statusTripUntukJadwal($record);
        if ($statusTrip !== null) {
            abort(422, "Jadwal tanggal {$record->tanggal} tidak dapat dihapus — trip supir pada tanggal ini " . $this->labelStatusTrip($statusTrip));
        }

        if ((string) $record->tanggal === now()->toDateString()) {
            $tripAktif = $this->tripRepo->findTripAktifUntukAktor(null, (string) $record->id_supir, null, null);
            if ($tripAktif !== null) {
                $status = str_replace('_', ' ', $tripAktif->status);
                abort(422, "Supir ini masih memiliki trip aktif di proyek {$tripAktif->nama_proyek} (status: {$status}) — jadwal hari ini tidak dapat dihapus sebelum trip diselesaikan/dibatalkan");
            }
        }

        $idProyek = (string) $record->id_proyek;
        $tanggal  = (string) $record->tanggal;

        $this->repo->delete($record);
        $this->alokasiService->hapusUntukJadwal((string) $record->id_supir, $tanggal);

        // Armada yang tadi dipakai supir ini bisa jadi kandidat pinjaman baru
        // buat supir lain di proyek yang sama pada tanggal itu — hitung ulang
        // otomatis, tanpa perlu tombol "Hitung Ulang" manual.
        $this->alokasiService->hitungUlangRentang($idProyek, $tanggal, $tanggal);
    }

    public function hariIniSaya(string $idSupir): ?object
    {
        return $this->repo->findAktifBySupirTanggal($idSupir, now()->toDateString());
    }
}
