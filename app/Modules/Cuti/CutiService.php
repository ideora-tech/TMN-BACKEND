<?php

declare(strict_types=1);

namespace App\Modules\Cuti;

use App\Modules\Cuti\Contracts\CutiRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CutiService
{
    public const JATAH_TAHUNAN = 12;

    public function __construct(
        private readonly CutiRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    // ── Jenis Cuti ───────────────────────────────────────────────

    public function listJenis(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $result = $this->repo->paginateJenis($idPerusahaan, $page, $limit, $search);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function createJenis(string $idPerusahaan, array $data): object
    {
        return $this->repo->createJenis(array_merge($data, ['id_perusahaan' => $idPerusahaan]));
    }

    public function updateJenis(string $id, string $idPerusahaan, array $data): object
    {
        return $this->repo->updateJenis($this->jenisOrFail($id, $idPerusahaan), $data);
    }

    public function deleteJenis(string $id, string $idPerusahaan): void
    {
        $this->repo->deleteJenis($this->jenisOrFail($id, $idPerusahaan));
    }

    // ── Pengajuan ────────────────────────────────────────────────

    public function listPengajuan(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $status = null, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): array
    {
        $result = $this->repo->paginatePengajuan($idPerusahaan, $page, $limit, $status, $search, $tanggalDari, $tanggalSampai);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function createPengajuan(string $idPerusahaan, array $data): object
    {
        $idKaryawan = $data['id_karyawan'] ?? null;
        $idSupir    = $data['id_supir'] ?? null;

        if (($idKaryawan === null) === ($idSupir === null)) {
            abort(422, 'Pilih tepat satu: karyawan atau supir');
        }

        if ($idKaryawan !== null) {
            $karyawan = $this->karyawanRepo->findById($idKaryawan);
            if ($karyawan === null || $karyawan->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Karyawan tidak ditemukan');
            }
        } else {
            $supir = $this->supirRepo->findById($idSupir);
            if ($supir === null || $supir->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Supir tidak ditemukan');
            }
        }

        $this->jenisOrFail($data['id_jenis_cuti'], $idPerusahaan);

        if ($this->repo->adaPengajuanTumpangTindih($idKaryawan, $idSupir, $data['tanggal_mulai'], $data['tanggal_selesai'])) {
            abort(422, 'Sudah ada pengajuan cuti lain (menunggu/disetujui) pada rentang tanggal tersebut');
        }

        $jumlahHari = Carbon::parse($data['tanggal_mulai'])->diffInDays(Carbon::parse($data['tanggal_selesai'])) + 1;

        return $this->repo->createPengajuan([
            'id_perusahaan'   => $idPerusahaan,
            'id_karyawan'     => $idKaryawan,
            'id_supir'        => $idSupir,
            'id_jenis_cuti'   => $data['id_jenis_cuti'],
            'tanggal_mulai'   => $data['tanggal_mulai'],
            'tanggal_selesai' => $data['tanggal_selesai'],
            'jumlah_hari'     => (int) $jumlahHari,
            'alasan'          => $data['alasan'] ?? null,
            'status'          => 'menunggu',
        ]);
    }

    public function setujui(string $id, string $idPerusahaan): object
    {
        $pengajuan = $this->pengajuanOrFail($id, $idPerusahaan);

        if ($pengajuan->status !== 'menunggu') {
            abort(422, 'Hanya pengajuan berstatus menunggu yang dapat disetujui');
        }

        $tahun = (int) Carbon::parse($pengajuan->tanggal_mulai)->format('Y');

        return DB::transaction(function () use ($pengajuan, $tahun) {
            if ((bool) $pengajuan->mengurangi_saldo) {
                $saldo = $this->hitungSisaSaldo($pengajuan->id_karyawan, $pengajuan->id_supir, $tahun);
                if ($saldo < $pengajuan->jumlah_hari) {
                    abort(422, "Saldo cuti tidak cukup (sisa {$saldo} hari, diajukan {$pengajuan->jumlah_hari} hari)");
                }

                $this->repo->insertLedger([
                    'id_perusahaan' => $pengajuan->id_perusahaan,
                    'id_karyawan'   => $pengajuan->id_karyawan,
                    'id_supir'      => $pengajuan->id_supir,
                    'tahun'         => $tahun,
                    'tipe'          => 'debit',
                    'jumlah_hari'   => $pengajuan->jumlah_hari,
                    'referensi_id'  => $pengajuan->id_pengajuan,
                    'keterangan'    => "Cuti {$pengajuan->nama_jenis} {$pengajuan->tanggal_mulai} s/d {$pengajuan->tanggal_selesai}",
                ]);
            }

            return $this->repo->updatePengajuan($pengajuan, [
                'status'        => 'disetujui',
                'diproses_oleh' => auth()->id(),
                'diproses_pada' => now(),
            ]);
        });
    }

    public function tolak(string $id, string $idPerusahaan, ?string $catatan = null): object
    {
        $pengajuan = $this->pengajuanOrFail($id, $idPerusahaan);

        if ($pengajuan->status !== 'menunggu') {
            abort(422, 'Hanya pengajuan berstatus menunggu yang dapat ditolak');
        }

        return $this->repo->updatePengajuan($pengajuan, [
            'status'         => 'ditolak',
            'diproses_oleh'  => auth()->id(),
            'diproses_pada'  => now(),
            'catatan_proses' => $catatan,
        ]);
    }

    public function batalkan(string $id, string $idPerusahaan): object
    {
        $pengajuan = $this->pengajuanOrFail($id, $idPerusahaan);

        if (!in_array($pengajuan->status, ['menunggu', 'disetujui'], true)) {
            abort(422, 'Pengajuan ini tidak dapat dibatalkan');
        }

        return DB::transaction(function () use ($pengajuan) {
            if ($pengajuan->status === 'disetujui') {
                $this->repo->softDeleteDebitByReferensi($pengajuan->id_pengajuan);
            }

            return $this->repo->updatePengajuan($pengajuan, [
                'status'        => 'dibatalkan',
                'diproses_oleh' => auth()->id(),
                'diproses_pada' => now(),
            ]);
        });
    }

    // ── Saldo ────────────────────────────────────────────────────

    public function saldoInfo(string $idPerusahaan, ?string $idKaryawan, ?string $idSupir, int $tahun): array
    {
        if (($idKaryawan === null) === ($idSupir === null)) {
            abort(422, 'Pilih tepat satu: karyawan atau supir');
        }

        $sum = $this->repo->sumLedger($idKaryawan, $idSupir, $tahun);

        return [
            'tahun'       => $tahun,
            'jatah'       => self::JATAH_TAHUNAN,
            'penyesuaian' => $sum->penyesuaian,
            'terpakai'    => $sum->terpakai,
            'sisa'        => self::JATAH_TAHUNAN + $sum->penyesuaian - $sum->terpakai,
            'riwayat'     => $this->repo->riwayatLedger($idKaryawan, $idSupir, $tahun),
        ];
    }

    public function rekapSaldo(string $idPerusahaan, int $tahun, int $page = 1, int $limit = 10, ?string $search = null): array
    {
        $ledger = collect($this->repo->sumLedgerSemua($idPerusahaan, $tahun));
        $byKaryawan = $ledger->filter(fn ($r) => $r->id_karyawan !== null)->keyBy('id_karyawan');
        $bySupir    = $ledger->filter(fn ($r) => $r->id_supir !== null)->keyBy('id_supir');

        $rows = [];

        foreach ($this->karyawanRepo->paginateByPerusahaan($idPerusahaan, 1, 500)->items() as $k) {
            if (!(bool) $k->aktif) continue;
            $sum = $byKaryawan->get($k->id_karyawan);
            $rows[] = $this->barisRekap('karyawan', $k->id_karyawan, $k->nama_karyawan, $sum);
        }

        foreach ($this->supirRepo->paginateByPerusahaan($idPerusahaan, 1, 500, 'aktif')->items() as $s) {
            $sum = $bySupir->get($s->id_supir);
            $rows[] = $this->barisRekap('supir', $s->id_supir, $s->nama, $sum);
        }

        if ($search !== null && $search !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => stripos($r['nama'], $search) !== false
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

    public function penyesuaian(string $idPerusahaan, array $data): object
    {
        $idKaryawan = $data['id_karyawan'] ?? null;
        $idSupir    = $data['id_supir'] ?? null;

        if (($idKaryawan === null) === ($idSupir === null)) {
            abort(422, 'Pilih tepat satu: karyawan atau supir');
        }

        return $this->repo->insertLedger([
            'id_perusahaan' => $idPerusahaan,
            'id_karyawan'   => $idKaryawan,
            'id_supir'      => $idSupir,
            'tahun'         => (int) $data['tahun'],
            'tipe'          => 'penyesuaian',
            'jumlah_hari'   => (int) $data['jumlah_hari'],
            'keterangan'    => $data['keterangan'] ?? null,
        ]);
    }

    public function orangCutiPadaTanggal(string $idPerusahaan, string $tanggal): array
    {
        return $this->repo->orangCutiPadaTanggal($idPerusahaan, $tanggal);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function hitungSisaSaldo(?string $idKaryawan, ?string $idSupir, int $tahun): int
    {
        $sum = $this->repo->sumLedger($idKaryawan, $idSupir, $tahun);
        return self::JATAH_TAHUNAN + $sum->penyesuaian - $sum->terpakai;
    }

    private function barisRekap(string $tipe, string $id, string $nama, ?object $sum): array
    {
        $penyesuaian = $sum->penyesuaian ?? 0;
        $terpakai    = $sum->terpakai ?? 0;

        return [
            'tipe'        => $tipe,
            'id'          => $id,
            'nama'        => $nama,
            'jatah'       => self::JATAH_TAHUNAN,
            'penyesuaian' => (int) $penyesuaian,
            'terpakai'    => (int) $terpakai,
            'sisa'        => self::JATAH_TAHUNAN + (int) $penyesuaian - (int) $terpakai,
        ];
    }

    private function jenisOrFail(string $id, string $idPerusahaan): object
    {
        $jenis = $this->repo->findJenisById($id);
        if ($jenis === null || $jenis->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Jenis cuti tidak ditemukan');
        }
        return $jenis;
    }

    private function pengajuanOrFail(string $id, string $idPerusahaan): object
    {
        $pengajuan = $this->repo->findPengajuanById($id);
        if ($pengajuan === null || $pengajuan->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pengajuan cuti tidak ditemukan');
        }
        return $pengajuan;
    }
}
