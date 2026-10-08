<?php

declare(strict_types=1);

namespace App\Modules\UangJalan;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\UangJalan\Contracts\UangJalanRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UangJalanService
{
    public const STATUS_BISA_DIUBAH = [
        ArusKasService::STATUS_MENUNGGU_APPROVAL,
        ArusKasService::STATUS_DITOLAK,
    ];

    public function __construct(
        private readonly UangJalanRepositoryInterface $repo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        ?string $status = null,
        ?string $dari = null,
        ?string $sampai = null,
        ?string $idProyek = null,
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status, $dari, $sampai, $idProyek);

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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Uang jalan tidak ditemukan');
        }

        return $record;
    }

    public function opsi(string $idPerusahaan): array
    {
        return $this->repo->opsiInternal($idPerusahaan);
    }

    public function opsiVendor(string $idVendor, string $idPerusahaan): array
    {
        if ($this->repo->findVendor($idVendor, $idPerusahaan) === null) {
            abort(404, 'Vendor tidak ditemukan');
        }

        return $this->repo->opsiVendor($idVendor);
    }

    public function opsiProyek(string $idPerusahaan): array
    {
        return $this->repo->opsiProyek($idPerusahaan);
    }

    public function opsiRuteProyek(string $idProyek, string $idPerusahaan): array
    {
        if ($this->repo->findProyek($idProyek, $idPerusahaan) === null) {
            abort(404, 'Proyek tidak ditemukan');
        }

        return $this->repo->opsiRuteProyek($idProyek);
    }

    public function tarifRateCard(string $idProyek, string $idRute, ?string $idArmada, ?string $idArmadaVendor, string $idPerusahaan): ?array
    {
        if ($this->repo->findProyek($idProyek, $idPerusahaan) === null) {
            abort(404, 'Proyek tidak ditemukan');
        }

        $jenis = null;
        if ($idArmada !== null) {
            $jenis = $this->repo->jenisKendaraanArmada($idArmada, $idPerusahaan);
        } elseif ($idArmadaVendor !== null) {
            $jenis = $this->repo->jenisKendaraanArmadaVendor($idArmadaVendor, $idPerusahaan);
        }

        $baris = $this->repo->rateCardRute($idProyek, $idRute, $jenis);
        if ($baris === null) {
            return null;
        }

        $tol   = round((float) ($baris->estimasi_tol ?? 0), 2);
        $bbm   = round((float) ($baris->estimasi_bbm ?? 0), 2);
        $lain  = round((float) ($baris->estimasi_biaya_lain ?? 0), 2);

        if ($tol + $bbm + $lain <= 0) {
            return null;
        }

        return [
            'tol_per_trip'        => $tol,
            'bbm_per_trip'        => $bbm,
            'biaya_lain_per_trip' => $lain,
            'uang_jalan_per_trip' => round($tol + $bbm + $lain, 2),
        ];
    }

    public function opsiPenugasan(string $idProyek, string $idPerusahaan): array
    {
        if ($this->repo->findProyek($idProyek, $idPerusahaan) === null) {
            abort(404, 'Proyek tidak ditemukan');
        }

        return $this->repo->opsiPenugasan($idProyek);
    }

    public function riwayat(string $id, string $idPerusahaan): array
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        return $this->arusKasService->infoPengajuanById((string) $record->id_pengajuan, $idPerusahaan);
    }

    public function create(array $data, string $idPerusahaan): object
    {
        return DB::transaction(function () use ($data, $idPerusahaan) {
            $isi = $this->rapikan($data, $idPerusahaan);
            $id  = (string) Str::uuid();

            $pengajuan = $this->arusKasService->buatPengajuanUangJalanManual(
                $idPerusahaan,
                $id,
                $isi['nominal'],
                $this->penerima($isi),
                $this->keterangan($isi),
            );

            return $this->repo->create(array_merge($isi, [
                'id_uang_jalan'    => $id,
                'id_perusahaan'    => $idPerusahaan,
                'nomor_uang_jalan' => $this->repo->nomorBerikutnya($idPerusahaan),
                'id_pengajuan'     => $pengajuan->id_pengajuan,
            ]));
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBisaDiubah($record, 'diubah');

        return DB::transaction(function () use ($record, $data, $idPerusahaan) {
            $isi = $this->rapikan($data, $idPerusahaan);

            $this->arusKasService->perbaruiPengajuanUangJalan(
                (string) $record->id_pengajuan,
                $idPerusahaan,
                $isi['nominal'],
                $this->penerima($isi),
                $this->keterangan($isi),
            );

            return $this->repo->update($record, $isi);
        }, 3);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBisaDiubah($record, 'dihapus');

        DB::transaction(function () use ($record, $idPerusahaan) {
            $this->arusKasService->hapusPengajuanUangJalan((string) $record->id_pengajuan, $idPerusahaan);
            $this->repo->delete($record);
        }, 3);
    }

    private function pastikanBisaDiubah(object $record, string $aksi): void
    {
        if (!in_array($record->status_pengajuan, self::STATUS_BISA_DIUBAH, true)) {
            abort(409, "Uang jalan hanya bisa {$aksi} saat status menunggu approval atau ditolak (status saat ini: " . ($record->status_pengajuan ?? 'tidak diketahui') . ')');
        }
    }

    private function rapikan(array $data, string $idPerusahaan): array
    {
        $tol     = round((float) ($data['tol_per_trip'] ?? 0), 2);
        $bbm     = round((float) ($data['bbm_per_trip'] ?? 0), 2);
        $lain    = round((float) ($data['biaya_lain_per_trip'] ?? 0), 2);
        $tarif   = round($tol + $bbm + $lain, 2);
        $trip    = (int) $data['jumlah_trip'];
        $catatan = isset($data['catatan']) ? trim((string) $data['catatan']) : '';

        if ($tarif < 1) {
            $this->tolak('tol_per_trip', 'Isi minimal salah satu rincian biaya per trip (tol, BBM, atau biaya lain)');
        }
        if ($tarif > 100000000) {
            $this->tolak('tol_per_trip', 'Total uang jalan per trip maksimal Rp 100.000.000');
        }

        $proyek = null;
        if (!empty($data['id_proyek'])) {
            $proyek = $this->repo->findProyek((string) $data['id_proyek'], $idPerusahaan)
                ?? $this->tolak('id_proyek', 'Proyek tidak ditemukan');
        }

        $idPenugasan = null;
        if (!empty($data['id_penugasan'])) {
            if ($proyek === null) {
                $this->tolak('id_penugasan', 'Pilih proyek terlebih dahulu');
            }
            $penugasan = $this->repo->findPenugasan((string) $data['id_penugasan'], $proyek->id_proyek)
                ?? $this->tolak('id_penugasan', 'Penugasan tidak ditemukan pada proyek ini');
            $idPenugasan = $penugasan->id_penugasan;
        }

        $rute = $proyek !== null
            ? ($this->repo->findRuteProyek((string) $data['id_rute'], $proyek->id_proyek)
                ?? $this->tolak('id_rute', 'Rute tidak terdaftar pada proyek ini'))
            : ($this->repo->findRute((string) $data['id_rute'], $idPerusahaan)
                ?? $this->tolak('id_rute', 'Rute tidak ditemukan'));

        return array_merge($this->resolusiDriver($data, $idPerusahaan), [
            'tanggal'             => $data['tanggal'],
            'tipe_driver'         => $data['tipe_driver'],
            'id_rute'             => $rute->id_rute,
            'rute'                => $rute->nama_rute,
            'id_proyek'           => $proyek?->id_proyek,
            'kode_proyek'         => $proyek?->kode_proyek,
            'nama_proyek'         => $proyek?->nama_proyek,
            'id_penugasan'        => $idPenugasan,
            'tol_per_trip'        => $tol,
            'bbm_per_trip'        => $bbm,
            'biaya_lain_per_trip' => $lain,
            'uang_jalan_per_trip' => $tarif,
            'jumlah_trip'         => $trip,
            'nominal'             => round($tarif * $trip, 2),
            'catatan'             => $catatan !== '' ? $catatan : null,
            'nomor_rekening'      => trim((string) $data['nomor_rekening']),
            'nama_bank'           => trim((string) $data['nama_bank']),
        ]);
    }

    private function resolusiDriver(array $data, string $idPerusahaan): array
    {
        if ($data['tipe_driver'] === 'internal') {
            $supir = $this->repo->findSupir((string) $data['id_supir'], $idPerusahaan)
                ?? $this->tolak('id_supir', 'Supir tidak ditemukan');
            $armada = $this->repo->findArmada((string) $data['id_armada'], $idPerusahaan)
                ?? $this->tolak('id_armada', 'Unit tidak ditemukan');

            return [
                'id_supir'         => $supir->id_supir,
                'id_armada'        => $armada->id_armada,
                'id_vendor'        => null,
                'id_supir_vendor'  => null,
                'id_armada_vendor' => null,
                'nama_driver'      => $supir->nama,
                'nama_vendor'      => null,
                'nopol'            => $armada->nopol,
            ];
        }

        $vendor = $this->repo->findVendor((string) $data['id_vendor'], $idPerusahaan)
            ?? $this->tolak('id_vendor', 'Vendor tidak ditemukan');
        $supirVendor = $this->repo->findSupirVendor((string) $data['id_supir_vendor'], $vendor->id_vendor)
            ?? $this->tolak('id_supir_vendor', 'Driver tidak terdaftar pada vendor ini');
        $armadaVendor = $this->repo->findArmadaVendor((string) $data['id_armada_vendor'], $vendor->id_vendor)
            ?? $this->tolak('id_armada_vendor', 'Unit tidak terdaftar pada vendor ini');

        return [
            'id_supir'         => null,
            'id_armada'        => null,
            'id_vendor'        => $vendor->id_vendor,
            'id_supir_vendor'  => $supirVendor->id_supir_vendor,
            'id_armada_vendor' => $armadaVendor->id_armada_vendor,
            'nama_driver'      => $supirVendor->nama,
            'nama_vendor'      => $vendor->nama_vendor,
            'nopol'            => $armadaVendor->nopol,
        ];
    }

    private function tolak(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => $pesan]);
    }

    private function penerima(array $isi): string
    {
        $nama = $isi['nama_driver'] . ($isi['nama_vendor'] !== null ? " ({$isi['nama_vendor']})" : '');

        return Str::limit($nama, 150, '');
    }

    private function keterangan(array $isi): string
    {
        $teks = sprintf(
            'Uang jalan %s — %s — %s (Rp %s/trip × %d trip)',
            $isi['nama_driver'],
            $isi['nopol'],
            $isi['rute'],
            number_format($isi['uang_jalan_per_trip'], 0, ',', '.'),
            $isi['jumlah_trip'],
        );

        return Str::limit($teks, 255, '');
    }
}
