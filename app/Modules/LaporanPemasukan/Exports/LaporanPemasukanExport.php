<?php

declare(strict_types=1);

namespace App\Modules\LaporanPemasukan\Exports;

use App\Support\AsalFrontend;
use App\Support\Exports\SheetTabelTautan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LaporanPemasukanExport implements WithMultipleSheets
{
    private const KOLOM_DOKUMEN = 10;
    private const KOLOM_BUKTI   = 11;

    public function __construct(
        private readonly array $laporan,
        private readonly string $namaPerusahaan,
        private readonly ?string $asalFrontend,
    ) {}

    public function sheets(): array
    {
        $periode = 'Periode ' . date('d/m/Y', strtotime($this->laporan['periode']['dari']))
            . ' s/d ' . date('d/m/Y', strtotime($this->laporan['periode']['sampai']));
        $judul    = $this->namaPerusahaan !== '' ? ' — ' . mb_strtoupper($this->namaPerusahaan) : '';
        $saringan = (string) ($this->laporan['saringan'] ?? '');

        return [
            new SheetTabelTautan('Ringkasan', 'LAPORAN PEMASUKAN' . $judul, $periode, ['Keterangan', 'Transaksi', 'Nilai'], $this->barisRingkasan(), [3]),
            $this->sheetRincian($judul, $saringan !== '' ? $periode . ' · ' . $saringan : $periode),
        ];
    }

    private function barisRingkasan(): Collection
    {
        $ringkasan = $this->laporan['ringkasan'];

        $rows = collect([
            ['Total pemasukan (uang diterima)', (int) $ringkasan['jumlah_transaksi'], (float) $ringkasan['total_pemasukan']],
            ['Dari pembayaran invoice klien', '', (float) $ringkasan['dari_invoice']],
            ['Pemasukan lain', '', (float) $ringkasan['pemasukan_lain']],
            ['Potongan oleh klien (tidak diterima, mis. PPh)', '', (float) $ringkasan['potongan_klien']],
            ['Invoice tercatat pada periode ini (dasar Arus Kas, termasuk yang masih draft)', (int) $ringkasan['invoice_terbit']['jumlah'], (float) $ringkasan['invoice_terbit']['total']],
            ['', '', ''],
            ['PER KATEGORI', '', ''],
        ]);

        foreach ($this->laporan['per_kategori'] as $kategori) {
            $rows->push([$kategori['label'], (int) $kategori['jumlah'], (float) $kategori['total']]);
        }

        return $rows;
    }

    private function sheetRincian(string $judul, string $subjudul): SheetTabelTautan
    {
        $rows    = collect();
        $dokumen = [];
        $bukti   = [];

        foreach (array_values($this->laporan['data']) as $i => $baris) {
            $rows->push([
                $i + 1,
                date('d/m/Y', strtotime($baris['tanggal'])),
                (string) $baris['nomor'],
                $baris['label_kategori'],
                (string) ($baris['pihak'] ?? ''),
                trim(implode(' — ', array_filter([(string) ($baris['no_referensi'] ?? ''), (string) ($baris['keterangan'] ?? '')]))),
                (float) $baris['nominal'],
                (float) $baris['potongan'] > 0
                    ? (float) $baris['potongan']
                    : '',
                (string) ($baris['oleh'] ?? ''),
                $baris['label_dokumen'],
                $baris['url_bukti'] !== null ? 'Lihat bukti' : '',
            ]);
            $dokumen[$i] = AsalFrontend::tautan($this->asalFrontend, $baris['link_dokumen']);
            $bukti[$i]   = $baris['url_bukti'];
        }

        $rows->push([
            '', '', '', '', '', 'TOTAL PEMASUKAN',
            (float) $this->laporan['rincian']['total'],
            (float) $this->laporan['rincian']['potongan'],
            '', '', '',
        ]);

        return new SheetTabelTautan(
            'Rincian',
            'RINCIAN PEMASUKAN' . $judul,
            $subjudul,
            ['No', 'Tanggal', 'Nomor', 'Kategori', 'Diterima Dari', 'Keterangan', 'Nominal', 'Potongan Klien', 'Dicatat Oleh', 'Dokumen', 'Bukti'],
            $rows,
            [7, 8],
            [self::KOLOM_DOKUMEN => $dokumen, self::KOLOM_BUKTI => $bukti],
        );
    }
}
