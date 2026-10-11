<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Exports;

use App\Modules\ArusKas\ArusKasService;
use App\Support\AsalFrontend;
use App\Support\Exports\SheetTabelTautan;
use App\Support\PenyimpananBerkas;
use Illuminate\Support\Collection;

class ArusKasExport extends SheetTabelTautan
{
    private const KOLOM_DOKUMEN = 9;
    private const KOLOM_BUKTI   = 10;

    private const LABEL_SUMBER = [
        'faktur'                => 'Invoice',
        'pengajuan_pengeluaran' => 'Pengajuan Pengeluaran',
        'pembayaran_vendor'     => 'Pembayaran Vendor',
        'pemasukan_manual'      => 'Pemasukan Manual',
        'payroll_periode'       => 'Payroll',
        'pembelian_sparepart'   => 'Pembelian Sparepart',
    ];

    public function __construct(Collection $data, ?string $dari = null, ?string $sampai = null, ?string $asalFrontend = null)
    {
        $rows        = collect();
        $dokumen     = [];
        $bukti       = [];
        $pemasukan   = 0.0;
        $pengeluaran = 0.0;

        foreach ($data->values() as $i => $row) {
            $masuk   = $row->arah === 'masuk';
            $nominal = (float) $row->nominal;
            if ($masuk) {
                $pemasukan += $nominal;
            } else {
                $pengeluaran += $nominal;
            }

            [$labelDokumen, $pathDokumen] = self::dokumen($row);
            $urlBukti = PenyimpananBerkas::url($row->url_bukti ?? null);

            $rows->push([
                $i + 1,
                date('d/m/Y', strtotime((string) $row->tanggal)),
                self::LABEL_SUMBER[$row->sumber] ?? ucfirst(str_replace('_', ' ', (string) $row->sumber)),
                self::kategori($row),
                (string) ($row->label ?? '-'),
                (string) ($row->keterangan ?? '-'),
                $masuk ? $nominal : '',
                $masuk ? '' : $nominal,
                $labelDokumen,
                $urlBukti !== null ? 'Lihat bukti' : '',
            ]);
            $dokumen[$i] = AsalFrontend::tautan($asalFrontend, $pathDokumen);
            $bukti[$i]   = $urlBukti;
        }

        $rows->push(['', '', '', '', '', 'TOTAL PEMASUKAN', round($pemasukan, 2), '', '', '']);
        $rows->push(['', '', '', '', '', 'TOTAL PENGELUARAN', '', round($pengeluaran, 2), '', '']);
        $rows->push(['', '', '', '', '', 'NETTO', round($pemasukan - $pengeluaran, 2), '', '', '']);

        parent::__construct(
            'Rincian Transaksi',
            'REKAP ARUS KAS',
            self::labelPeriode($dari, $sampai),
            ['No', 'Tanggal', 'Sumber', 'Kategori', 'Referensi', 'Keterangan', 'Masuk', 'Keluar', 'Dokumen', 'Bukti'],
            $rows,
            [7, 8],
            [self::KOLOM_DOKUMEN => $dokumen, self::KOLOM_BUKTI => $bukti],
        );
    }

    public static function labelPeriode(?string $dari, ?string $sampai): string
    {
        if ($dari && $sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($dari)) . ' — ' . date('d/m/Y', strtotime($sampai));
        }
        if ($dari) {
            return 'Periode: sejak ' . date('d/m/Y', strtotime($dari));
        }
        if ($sampai) {
            return 'Periode: s.d. ' . date('d/m/Y', strtotime($sampai));
        }
        return 'Semua Periode';
    }

    private static function kategori(object $row): string
    {
        return match ($row->sumber) {
            'faktur'                => 'Invoice Klien',
            'pembayaran_vendor'     => 'Pembayaran Vendor',
            'pengajuan_pengeluaran' => ArusKasService::labelKategori($row->kategori ?? null),
            'pemasukan_manual'      => ArusKasService::labelKategoriPemasukan($row->kategori ?? null),
            default                 => (string) ($row->kategori ?? ''),
        };
    }

    /** @return array{0: string, 1: string|null} */
    private static function dokumen(object $row): array
    {
        $id = trim((string) ($row->id_referensi ?? ''));

        return match ($row->sumber) {
            'faktur'                => $id !== '' ? ['Invoice', '/faktur/' . rawurlencode($id)] : ['', null],
            'pembayaran_vendor'     => $id !== '' ? ['Invoice Vendor', '/invoice-vendor/' . rawurlencode($id)] : ['', null],
            'pengajuan_pengeluaran' => ['Proses Pembayaran', '/proses-pembayaran?tab=ditransfer'],
            'pemasukan_manual'      => ['Pemasukan Manual', '/arus-kas?tab=pemasukan'],
            default                 => ['', null],
        };
    }
}
