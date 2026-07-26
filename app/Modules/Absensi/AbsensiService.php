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
                'status'     => $entry['status'],
                'jam_masuk'  => $entry['jam_masuk'] ?? null,
                'jam_pulang' => $entry['jam_pulang'] ?? null,
                'keterangan' => $entry['keterangan'] ?? null,
            ]);
            $tersimpan++;
        }

        return ['tersimpan' => $tersimpan, 'dilewati' => $dilewati];
    }

    public function rekapBulanan(string $idPerusahaan, string $bulan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $awal  = Carbon::parse($bulan . '-01')->startOfMonth();
        $akhir = $awal->copy()->endOfMonth();
        $awalStr  = $awal->toDateString();
        $akhirStr = $akhir->toDateString();

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
            if (!(bool) $k->aktif) continue;

            $c = $counts->get($k->id_karyawan, collect())->keyBy('status');
            $baris = [
                'id_karyawan' => $k->id_karyawan,
                'nik'         => $k->nik,
                'nama'        => $k->nama_karyawan,
                'cuti'        => $cutiHari[$k->id_karyawan] ?? 0,
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
