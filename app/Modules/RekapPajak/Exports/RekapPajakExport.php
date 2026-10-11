<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak\Exports;

use App\Modules\RekapPajak\RekapPajakService;
use App\Support\AsalFrontend;
use App\Support\Exports\SheetTabelTautan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RekapPajakExport implements WithMultipleSheets
{
    private const KOLOM_DOKUMEN = 12;

    private const SHEET = [
        RekapPajakService::PPN_KELUARAN       => ['nama' => 'PPN Keluaran',       'pihak' => 'Klien',    'dasar' => 'DPP'],
        RekapPajakService::PPN_MASUKAN        => ['nama' => 'PPN Masukan',        'pihak' => 'Vendor / Supplier', 'dasar' => 'DPP'],
        RekapPajakService::PPH_VENDOR         => ['nama' => 'PPh Vendor',         'pihak' => 'Vendor',   'dasar' => 'DPP'],
        RekapPajakService::PPH21              => ['nama' => 'PPh 21',             'pihak' => 'Karyawan', 'dasar' => 'Penghasilan Bruto'],
        RekapPajakService::PPH_DIPOTONG_KLIEN => ['nama' => 'PPh Dipotong Klien', 'pihak' => 'Klien',    'dasar' => 'Uang Diterima'],
        RekapPajakService::PAJAK_LAIN         => ['nama' => 'Pajak Lain Invoice', 'pihak' => 'Klien',    'dasar' => 'DPP'],
    ];

    public function __construct(
        private readonly array $rekap,
        private readonly string $namaPerusahaan,
        private readonly ?string $asalFrontend,
    ) {}

    public function sheets(): array
    {
        $periode = $this->rekap['periode']['label'];
        $judul   = $this->namaPerusahaan !== '' ? ' — ' . mb_strtoupper($this->namaPerusahaan) : '';

        $sheets = [
            new SheetTabelTautan('Ringkasan', 'REKAP PAJAK' . $judul, $periode, ['Keterangan', 'Dasar Pengenaan', 'Pajak', 'Jumlah Baris'], $this->barisRingkasan(), [2, 3]),
            new SheetTabelTautan(
                'Per Masa',
                'REKAP PAJAK PER MASA' . $judul,
                $periode,
                ['Masa Pajak', 'PPN Keluaran', 'PPN Masukan', 'PPN Kurang/(Lebih) Bayar', 'PPh Dipotong dari Vendor', 'PPh 21 Karyawan', 'PPh Dipotong oleh Klien'],
                $this->barisPerMasa(),
                [2, 3, 4, 5, 6, 7],
            ),
        ];

        foreach (self::SHEET as $jenis => $meta) {
            $baris = $this->rekap['baris'][$jenis] ?? [];
            if ($baris === [] && $jenis === RekapPajakService::PAJAK_LAIN) {
                continue;
            }
            $sheets[] = $this->sheetRincian($jenis, $meta, $baris, $judul, $periode);
        }

        return $sheets;
    }

    private function barisRingkasan(): Collection
    {
        $r = $this->rekap['ringkasan'];

        $rows = collect([
            ['PPN Keluaran (invoice klien)', (float) $r[RekapPajakService::PPN_KELUARAN]['dpp'], (float) $r[RekapPajakService::PPN_KELUARAN]['pajak'], (int) $r[RekapPajakService::PPN_KELUARAN]['jumlah']],
            ['PPN Masukan (invoice vendor & pembelian)', (float) $r[RekapPajakService::PPN_MASUKAN]['dpp'], (float) $r[RekapPajakService::PPN_MASUKAN]['pajak'], (int) $r[RekapPajakService::PPN_MASUKAN]['jumlah']],
            ['PPN Kurang/(Lebih) Bayar', '', (float) $r['ppn_kurang_bayar'], ''],
            ['', '', '', ''],
            ['PPh dipotong dari vendor (wajib disetor)', (float) $r[RekapPajakService::PPH_VENDOR]['dpp'], (float) $r[RekapPajakService::PPH_VENDOR]['pajak'], (int) $r[RekapPajakService::PPH_VENDOR]['jumlah']],
            ['PPh 21 karyawan (wajib disetor)', (float) $r[RekapPajakService::PPH21]['dpp'], (float) $r[RekapPajakService::PPH21]['pajak'], (int) $r[RekapPajakService::PPH21]['jumlah']],
            ['PPh dipotong oleh klien (kredit pajak)', '', (float) $r[RekapPajakService::PPH_DIPOTONG_KLIEN]['pajak'], (int) $r[RekapPajakService::PPH_DIPOTONG_KLIEN]['jumlah']],
            ['', '', '', ''],
            ['Invoice klien tanpa pajak', (float) $r['invoice_tanpa_pajak']['dpp'], '', (int) $r['invoice_tanpa_pajak']['jumlah']],
            ['Potongan klien selain PPh (mis. biaya transfer)', '', (float) $r['potongan_lain_klien']['nominal'], (int) $r['potongan_lain_klien']['jumlah']],
        ]);

        if ($r[RekapPajakService::PAJAK_LAIN]['jumlah'] > 0) {
            $rows->push(['Pajak lain di invoice klien (bukan PPN)', (float) $r[RekapPajakService::PAJAK_LAIN]['dpp'], (float) $r[RekapPajakService::PAJAK_LAIN]['pajak'], (int) $r[RekapPajakService::PAJAK_LAIN]['jumlah']]);
        }

        return $rows;
    }

    private function barisPerMasa(): Collection
    {
        $kolom = [
            RekapPajakService::PPN_KELUARAN, RekapPajakService::PPN_MASUKAN, 'ppn_kurang_bayar',
            RekapPajakService::PPH_VENDOR, RekapPajakService::PPH21, RekapPajakService::PPH_DIPOTONG_KLIEN,
        ];

        $rows  = collect();
        $total = array_fill_keys($kolom, 0.0);
        foreach ($this->rekap['per_masa'] as $masa) {
            $baris = [$masa['label']];
            foreach ($kolom as $kunci) {
                $baris[]        = (float) $masa[$kunci];
                $total[$kunci] += (float) $masa[$kunci];
            }
            $rows->push($baris);
        }
        $rows->push(['TOTAL', ...array_map(static fn (float $nilai) => round($nilai, 2), array_values($total))]);

        return $rows;
    }

    private function sheetRincian(string $jenis, array $meta, array $baris, string $judul, string $periode): SheetTabelTautan
    {
        $rows   = collect();
        $tautan = [];

        foreach (array_values($baris) as $i => $satu) {
            $rows->push([
                $i + 1,
                date('d/m/Y', strtotime($satu['tanggal'])),
                $satu['masa'],
                $satu['nomor'],
                (string) ($satu['pihak'] ?? ''),
                (string) ($satu['npwp'] ?? ''),
                (string) ($satu['keterangan'] ?? ''),
                (float) $satu['dpp'],
                $satu['tarif'] !== null ? (float) $satu['tarif'] : '',
                (float) $satu['pajak'],
                $satu['sumber'],
                'Buka dokumen',
            ]);
            $tautan[$i] = AsalFrontend::tautan($this->asalFrontend, $satu['link']);
        }

        $rows->push([
            '', '', '', '', '', '', 'TOTAL',
            round(array_sum(array_column($baris, 'dpp')), 2),
            '',
            round(array_sum(array_column($baris, 'pajak')), 2),
            '', '',
        ]);

        return new SheetTabelTautan(
            $meta['nama'],
            mb_strtoupper(RekapPajakService::LABEL_JENIS[$jenis]) . $judul,
            $periode,
            ['No', 'Tanggal', 'Masa', 'Nomor', $meta['pihak'], 'NPWP', 'Keterangan', $meta['dasar'], 'Tarif (%)', 'Pajak', 'Sumber', 'Dokumen'],
            $rows,
            [8, 10],
            [self::KOLOM_DOKUMEN => $tautan],
        );
    }
}
