<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Exports;

use App\Modules\KasKecil\KasKecilService;
use App\Support\Exports\SheetTabelTautan;
use App\Support\PenyimpananBerkas;

class BukuKasKecilExport extends SheetTabelTautan
{
    private const KOLOM_BUKTI = 12;

    public function __construct(array $buku, string $namaPerusahaan)
    {
        $rows  = collect([['', '', '', 'Saldo awal periode', '', '', '', '', '', (float) $buku['saldo_awal'], '', '']]);
        $bukti = [];

        foreach (array_values($buku['baris']) as $i => $baris) {
            $url   = PenyimpananBerkas::url($baris->url_bukti);
            $masuk = $baris->arah === 'masuk';

            $rows->push([
                $i + 1,
                date('d/m/Y', strtotime((string) $baris->tanggal)),
                $baris->nomor_transaksi,
                KasKecilService::LABEL_JENIS[$baris->jenis] ?? $baris->jenis,
                KasKecilService::labelKategori($baris->kategori) ?? '',
                (string) ($baris->penerima ?? ''),
                (string) $baris->keterangan,
                $masuk ? (float) $baris->nominal : '',
                $masuk ? '' : (float) $baris->nominal,
                (float) $baris->saldo,
                (string) ($baris->dicatat_oleh ?? ''),
                $url !== null ? 'Lihat bukti' : '',
            ]);
            $bukti[$i + 1] = $url;
        }

        $rows->push(['', '', '', 'TOTAL', '', '', '', (float) $buku['total_masuk'], (float) $buku['total_keluar'], (float) $buku['saldo_akhir'], '', '']);

        parent::__construct(
            'Buku Kas Kecil',
            'BUKU KAS KECIL' . ($namaPerusahaan !== '' ? ' — ' . mb_strtoupper($namaPerusahaan) : ''),
            'Periode ' . date('d/m/Y', strtotime($buku['periode']['dari'])) . ' s/d ' . date('d/m/Y', strtotime($buku['periode']['sampai'])),
            ['No', 'Tanggal', 'Nomor', 'Jenis', 'Kategori', 'Dibayar Kepada', 'Keterangan', 'Masuk', 'Keluar', 'Saldo', 'Dicatat Oleh', 'Bukti'],
            $rows,
            [8, 9, 10],
            [self::KOLOM_BUKTI => $bukti],
        );
    }
}
