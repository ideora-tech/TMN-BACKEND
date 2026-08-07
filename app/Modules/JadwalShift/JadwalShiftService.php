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

        foreach ($rows as $row) {
            $row->status_trip = $statusMap["{$row->id_supir}|{$row->tanggal}"] ?? null;
        }

        return $rows;
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

        return [
            'supir'   => $this->repo->supirTerdaftarDiProyek($idProyek),
            'tanggal' => $tanggal,
        ];
    }

    /**
     * Import matriks jadwal shift: baris = supir (No SIM + nama shift),
     * kolom ke-4 dst = tanggal, isi sel H = dijadwalkan, selain itu dilewati.
     * Gagal per-item (baris/tanggal bermasalah dilaporkan, sisanya tetap masuk),
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

        $tanggalKolom = [];
        foreach (array_slice($rows[0], 3, null, true) as $kolom => $nilai) {
            $tanggal = $this->parseTanggalHeader($nilai);
            if ($tanggal !== null) {
                $tanggalKolom[$kolom] = $tanggal;
            }
        }
        if ($tanggalKolom === []) {
            abort(422, 'Kolom tanggal tidak ditemukan pada baris header (mulai kolom ke-4)');
        }

        return DB::transaction(function () use ($rows, $tanggalKolom, $idProyek, $idPerusahaan) {
            $sukses    = 0;
            $gagal     = [];
            $terjadwal = [];

            foreach (array_slice($rows, 1, null, true) as $idx => $row) {
                $barisKe   = $idx + 1;
                $noSim     = trim((string) ($row[0] ?? ''));
                $namaShift = trim((string) ($row[2] ?? ''));

                if ($noSim === '' && $namaShift === '') {
                    continue;
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
                        $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Tanggal {$tanggal}: sudah dijadwalkan shift {$ada->shift_nama}"];
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

            return ['sukses' => $sukses, 'gagal' => $gagal];
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

    public function updateShift(string $id, string $idShift): object
    {
        $record = $this->findOrFail($id);
        return $this->repo->updateShift($record, $idShift);
    }

    /**
     * Jadwal hari ini tidak boleh dihapus selama supirnya masih punya trip
     * aktif — menghapus jadwal memicu penghitungan ulang alokasi armada, dan
     * armada yang sedang dipakai trip aktif bisa keliru ditawarkan sebagai
     * "menganggur" ke supir lain (lihat AlokasiArmadaRepository::kandidatArmadaNganggur).
     */
    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

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
