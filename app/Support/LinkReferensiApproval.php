<?php

declare(strict_types=1);

namespace App\Support;

class LinkReferensiApproval
{
    public static function untuk(string $kode, string $idReferensi): string
    {
        return match ($kode) {
            'penawaran'         => "/penawaran/{$idReferensi}",
            'proyek'            => "/project/{$idReferensi}",
            'faktur'            => "/faktur/{$idReferensi}",
            'invoice_vendor'    => "/invoice-vendor/{$idReferensi}",
            'kontrak_vendor'    => "/kontrak-vendor/{$idReferensi}",
            'permintaan_vendor' => "/permintaan-vendor/{$idReferensi}",
            'permintaan_pembelian', 'permintaan_pembelian_aset' => "/permintaan-pembelian?detail={$idReferensi}",
            'pengajuan_pengeluaran', 'uang_jalan', 'legalitas', 'perawatan', 'sparepart',
            'penggajian', 'pembelian_aset', 'pembayaran_pinjaman', 'pengadaan', 'lainnya',
            'persetujuan_transfer', 'pembayaran_vendor' => '/proses-pembayaran',
            default             => '/persetujuan-saya',
        };
    }

    public static function menungguSaya(string $idApproval): string
    {
        return "/persetujuan-saya?id_approval={$idApproval}";
    }
}
