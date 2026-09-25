<?php

declare(strict_types=1);

namespace App\Modules\Pengadaan;

use App\Modules\PermintaanPembelian\Contracts\PermintaanPembelianRepositoryInterface;
use App\Modules\PermintaanVendor\Contracts\PermintaanVendorRepositoryInterface;

class PengadaanService
{
    private const BATAS_MENUNGGU = 20;

    public function __construct(
        private readonly PermintaanPembelianRepositoryInterface $permintaanPembelianRepo,
        private readonly PermintaanVendorRepositoryInterface $permintaanVendorRepo,
    ) {}

    public function ringkasan(string $idPerusahaan): array
    {
        $prMenunggu = array_map(static fn (object $r) => [
            'id_permintaan'      => $r->id_permintaan,
            'nomor_permintaan'   => $r->nomor_permintaan,
            'judul'              => $r->judul,
            'status'             => $r->status,
            'tipe'               => $r->tipe,
            'tanggal_permintaan' => $r->tanggal_permintaan,
            'username_pengaju'   => $r->username_pengaju,
            'total_estimasi'     => (float) $r->total_estimasi,
        ], $this->permintaanPembelianRepo->listMenungguDiproses($idPerusahaan, self::BATAS_MENUNGGU));

        $pvMenunggu = array_map(static fn (object $r) => [
            'id_permintaan'    => $r->id_permintaan,
            'nomor_permintaan' => $r->nomor_permintaan,
            'nama_proyek'      => $r->nama_proyek,
            'status'           => $r->status,
            'mekanisme'        => $r->mekanisme,
            'jumlah_unit'      => (int) $r->jumlah_unit,
            'periode_dari'     => $r->periode_dari,
            'periode_sampai'   => $r->periode_sampai,
            'dibuat_pada'      => $r->dibuat_pada,
        ], $this->permintaanVendorRepo->listMenungguDiproses($idPerusahaan, self::BATAS_MENUNGGU));

        return [
            'pr' => [
                'ringkasan' => $this->permintaanPembelianRepo->ringkasanStatus($idPerusahaan),
                'menunggu'  => $prMenunggu,
            ],
            'permintaan_vendor' => [
                'ringkasan' => $this->permintaanVendorRepo->ringkasanStatus($idPerusahaan),
                'menunggu'  => $pvMenunggu,
            ],
        ];
    }
}
