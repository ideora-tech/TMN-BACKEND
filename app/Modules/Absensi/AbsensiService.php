<?php

declare(strict_types=1);

namespace App\Modules\Absensi;

use App\Modules\Absensi\Contracts\AbsensiRepositoryInterface;
use App\Modules\Cuti\Contracts\CutiRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use Illuminate\Support\Carbon;

class AbsensiService
{
    public const STATUS_VALID = ['hadir', 'terlambat', 'izin', 'sakit', 'alpha'];

    public function __construct(
        private readonly AbsensiRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
        private readonly CutiRepositoryInterface $cutiRepo,
    ) {}

    /** Daftar karyawan aktif + absensi tercatat + penanda cuti untuk satu tanggal. */
    public function harian(string $idPerusahaan, string $tanggal): array
    {
        $absensi = collect($this->repo->findByTanggal($idPerusahaan, $tanggal))->keyBy('id_karyawan');

        $cuti = collect($this->cutiRepo->orangCutiPadaTanggal($idPerusahaan, $tanggal))
            ->pluck('id_karyawan')
            ->filter()
            ->flip();

        $rows = [];
        foreach ($this->karyawanRepo->paginateByPerusahaan($idPerusahaan, 1, 500)->items() as $k) {
            if (!(bool) $k->aktif) continue;

            $a = $absensi->get($k->id_karyawan);
            $rows[] = [
                'id_karyawan' => $k->id_karyawan,
                'nik'         => $k->nik,
                'nama'        => $k->nama_karyawan,
                'sedang_cuti' => $cuti->has($k->id_karyawan),
                'status'      => $a->status ?? null,
                'jam_masuk'   => $a->jam_masuk ?? null,
                'jam_pulang'  => $a->jam_pulang ?? null,
                'keterangan'  => $a->keterangan ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Simpan absensi massal untuk satu tanggal. Entri untuk karyawan yang
     * sedang cuti disetujui dilewati (status cuti otomatis, bukan diinput).
     * Entri dengan status null menghapus catatan yang ada (batal input).
     */
    public function simpanHarian(string $idPerusahaan, string $tanggal, array $entries): array
    {
        $cuti = collect($this->cutiRepo->orangCutiPadaTanggal($idPerusahaan, $tanggal))
            ->pluck('id_karyawan')
            ->filter()
            ->flip();

        $tersimpan = 0;
        $dilewati  = 0;

        foreach ($entries as $entry) {
            $idKaryawan = $entry['id_karyawan'];

            $karyawan = $this->karyawanRepo->findById($idKaryawan);
            if ($karyawan === null || $karyawan->id_perusahaan !== $idPerusahaan) {
                $dilewati++;
                continue;
            }

            if ($cuti->has($idKaryawan)) {
                $dilewati++;
                continue;
            }

            if (($entry['status'] ?? null) === null) {
                $this->repo->hapusByKaryawanTanggal($idKaryawan, $tanggal);
                continue;
            }

            $this->repo->upsert($idPerusahaan, $idKaryawan, $tanggal, [
                'status'         => $entry['status'],
                'jam_masuk'      => $entry['jam_masuk'] ?? null,
                'jam_pulang'     => $entry['jam_pulang'] ?? null,
                'keterangan'     => $entry['keterangan'] ?? null,
                'pulang_mandiri' => 0,
            ]);
            $tersimpan++;
        }

        return ['tersimpan' => $tersimpan, 'dilewati' => $dilewati];
    }

    public function pengaturan(string $idPerusahaan): array
    {
        $row = $this->repo->getPengaturan($idPerusahaan);

        return [
            'jam_masuk'  => $row ? substr($row->jam_masuk, 0, 5) : '08:00',
            'jam_pulang' => $row ? substr($row->jam_pulang, 0, 5) : '17:00',
            'toleransi_terlambat_menit' => $row ? (int) $row->toleransi_terlambat_menit : 15,
        ];
    }

    public function simpanPengaturan(string $idPerusahaan, array $data): array
    {
        $this->repo->upsertPengaturan($idPerusahaan, $data);
        return $this->pengaturan($idPerusahaan);
    }

    public function absensiSaya(string $idPerusahaan, ?string $idKaryawan): ?object
    {
        $this->pastikanKaryawan($idKaryawan);
        return $this->repo->findByKaryawanTanggal($idKaryawan, now()->toDateString());
    }

    public function absenMasuk(string $idPerusahaan, ?string $idKaryawan, array $lokasi): object
    {
        $this->pastikanKaryawan($idKaryawan);
        $tanggal = now()->toDateString();

        $existing = $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
        if ($existing !== null && $existing->jam_masuk !== null) {
            abort(422, 'Sudah absen masuk hari ini');
        }

        $cuti = collect($this->cutiRepo->orangCutiPadaTanggal($idPerusahaan, $tanggal))
            ->pluck('id_karyawan')
            ->contains($idKaryawan);
        if ($cuti) {
            abort(422, 'Anda tercatat sedang cuti hari ini');
        }

        if ($existing !== null && in_array($existing->status, ['izin', 'sakit', 'alpha'], true)) {
            abort(422, 'Absensi Anda hari ini sudah dicatat admin: ' . $existing->status);
        }

        $pengaturan = $this->pengaturan($idPerusahaan);
        $batas = Carbon::parse($tanggal . ' ' . $pengaturan['jam_masuk'])
            ->addMinutes($pengaturan['toleransi_terlambat_menit']);

        $this->repo->upsert($idPerusahaan, $idKaryawan, $tanggal, [
            'status'          => now()->greaterThan($batas) ? 'terlambat' : 'hadir',
            'jam_masuk'       => now()->format('H:i:s'),
            'latitude_masuk'  => $lokasi['latitude'] ?? null,
            'longitude_masuk' => $lokasi['longitude'] ?? null,
            'alamat_masuk'    => $lokasi['alamat'] ?? null,
        ]);

        return $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
    }

    public function absenPulang(string $idPerusahaan, ?string $idKaryawan, array $lokasi): object
    {
        $this->pastikanKaryawan($idKaryawan);
        $tanggal = now()->toDateString();

        $existing = $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
        if ($existing === null || $existing->jam_masuk === null) {
            abort(422, 'Belum absen masuk hari ini');
        }
        if ($existing->jam_pulang !== null) {
            abort(422, 'Sudah absen pulang hari ini');
        }

        $this->repo->upsert($idPerusahaan, $idKaryawan, $tanggal, [
            'jam_pulang'       => now()->format('H:i:s'),
            'latitude_pulang'  => $lokasi['latitude'] ?? null,
            'longitude_pulang' => $lokasi['longitude'] ?? null,
            'alamat_pulang'    => $lokasi['alamat'] ?? null,
            'pulang_mandiri'   => 1,
        ]);

        return $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
    }

    private function pastikanKaryawan(?string $idKaryawan): void
    {
        if ($idKaryawan === null || $idKaryawan === '') {
            abort(422, 'Akun Anda tidak tertaut dengan data karyawan');
        }
    }

    /** Menit lembur per karyawan per HARI (selisih jam_pulang aktual vs standar) — per hari karena pengali 1,5×/2× berlaku per blok lembur harian. */
    private function hitungLemburHarian(string $idPerusahaan, string $awal, string $akhir): array
    {
        $pengaturan = $this->pengaturan($idPerusahaan);
        $batas = Carbon::parse('2000-01-01 ' . $pengaturan['jam_pulang']);

        $lembur = [];
        foreach ($this->repo->jamPulangDalamRentang($idPerusahaan, $awal, $akhir) as $row) {
            $pulang = Carbon::parse('2000-01-01 ' . $row->jam_pulang);
            if ($pulang->greaterThan($batas)) {
                $lembur[$row->id_karyawan][] = (int) $batas->diffInMinutes($pulang);
            }
        }

        return $lembur;
    }

    /** Upah lembur formula pemerintah: upah sejam = gaji/173; per hari jam pertama ×1,5, jam berikutnya ×2. */
    private function hitungUpahLembur(float $gajiPokok, array $menitHarian): int
    {
        $upahSejam = $gajiPokok / 173;
        $total = 0.0;

        foreach ($menitHarian as $menit) {
            $jam = $menit / 60;
            $jamPertama  = min(1, $jam);
            $jamBerikut  = max(0, $jam - 1);
            $total += $upahSejam * (1.5 * $jamPertama + 2 * $jamBerikut);
        }

        return (int) round($total);
    }

    public function rekapBulanan(string $idPerusahaan, string $bulan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $awal  = Carbon::parse($bulan . '-01')->startOfMonth();
        $akhir = $awal->copy()->endOfMonth();

        return $this->rekapRentang($idPerusahaan, $awal->toDateString(), $akhir->toDateString(), $page, $limit, $search);
    }

    public function rekapRentang(string $idPerusahaan, string $awalStr, string $akhirStr, int $page = 1, int $limit = 10, ?string $search = null, bool $termasukNonAktif = false): array
    {
        $awal  = Carbon::parse($awalStr);
        $akhir = Carbon::parse($akhirStr);

        $lemburHarian = $this->hitungLemburHarian($idPerusahaan, $awalStr, $akhirStr);

        $counts = collect($this->repo->rekapBulanan($idPerusahaan, $awalStr, $akhirStr))
            ->groupBy('id_karyawan');

        $cutiHari = [];
        foreach ($this->repo->cutiDisetujuiDalamRentang($idPerusahaan, $awalStr, $akhirStr) as $c) {
            $mulai   = Carbon::parse($c->tanggal_mulai)->max($awal);
            $selesai = Carbon::parse($c->tanggal_selesai)->min($akhir);
            $hari    = $mulai->diffInDays($selesai) + 1;
            $cutiHari[$c->id_karyawan] = ($cutiHari[$c->id_karyawan] ?? 0) + (int) $hari;
        }

        $rows = [];
        foreach ($this->karyawanRepo->paginateByPerusahaan($idPerusahaan, 1, 500)->items() as $k) {
            if (!(bool) $k->aktif && !$termasukNonAktif) continue;

            $c = $counts->get($k->id_karyawan, collect())->keyBy('status');
            $harian = $lemburHarian[$k->id_karyawan] ?? [];
            $baris = [
                'id_karyawan'   => $k->id_karyawan,
                'nik'           => $k->nik,
                'nama'          => $k->nama_karyawan,
                'aktif'         => (bool) $k->aktif,
                'tanggal_masuk' => $k->tanggal_masuk,
                'gaji_pokok'    => (float) $k->gaji_pokok,
                'status_ptkp'   => $k->status_ptkp ?? null,
                'ikut_bpjs_kesehatan'       => (bool) ($k->ikut_bpjs_kesehatan ?? false),
                'ikut_bpjs_ketenagakerjaan' => (bool) ($k->ikut_bpjs_ketenagakerjaan ?? false),
                'override_persen_bpjs_kesehatan' => $k->override_persen_bpjs_kesehatan !== null ? (float) $k->override_persen_bpjs_kesehatan : null,
                'override_persen_bpjs_jht'       => $k->override_persen_bpjs_jht !== null ? (float) $k->override_persen_bpjs_jht : null,
                'override_persen_bpjs_jp'        => $k->override_persen_bpjs_jp !== null ? (float) $k->override_persen_bpjs_jp : null,
                'override_plafon_bpjs_kesehatan' => $k->override_plafon_bpjs_kesehatan !== null ? (float) $k->override_plafon_bpjs_kesehatan : null,
                'tunjangan_jabatan' => $k->override_tunjangan_jabatan !== null
                    ? (float) $k->override_tunjangan_jabatan
                    : (float) ($k->jabatan_tunjangan ?? 0),
                'cuti'          => $cutiHari[$k->id_karyawan] ?? 0,
                'lembur_menit'  => array_sum($harian),
                'lembur_rupiah' => $this->hitungUpahLembur((float) $k->gaji_pokok, $harian),
            ];
            foreach (self::STATUS_VALID as $status) {
                $baris[$status] = (int) ($c->get($status)->jumlah ?? 0);
            }
            $rows[] = $baris;
        }

        if ($search !== null && $search !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => stripos($r['nama'], $search) !== false || stripos($r['nik'], $search) !== false
            ));
        }

        $total = count($rows);
        $rows  = array_slice($rows, ($page - 1) * $limit, $limit);

        return [
            'data' => $rows,
            'meta' => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'totalPages' => (int) ceil($total / max($limit, 1)),
            ],
        ];
    }
}
