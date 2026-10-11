<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran\Exports;

use App\Support\AsalFrontend;
use App\Support\Exports\SheetTabelTautan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LaporanPengeluaranExport implements WithMultipleSheets
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
        $judul   = $this->namaPerusahaan !== '' ? ' — ' . mb_strtoupper($this->namaPerusahaan) : '';

        return [
            new SheetTabelTautan('Ringkasan', 'LAPORAN PENGELUARAN' . $judul, $this->subjudul($periode, 'saringan_ringkasan'), ['Keterangan', 'Transaksi', 'Nilai'], $this->barisRingkasan(), [3]),
            $this->sheetRincian($judul, $this->subjudul($periode, 'saringan')),
        ];
    }

    private function subjudul(string $periode, string $kunci): string
    {
        $saringan = (string) ($this->laporan[$kunci] ?? '');

        return $saringan !== '' ? $periode . ' · ' . $saringan : $periode;
    }

    private function barisRingkasan(): Collection
    {
        $ringkasan = $this->laporan['ringkasan'];

        $rows = collect([
            ['Total pengeluaran', '', (float) $ringkasan['total_pengeluaran']],
            ['Dibayar dari rekening', '', (float) $ringkasan['dari_rekening']],
            ['Dibayar dari kas kecil', '', (float) $ringkasan['dari_kas_kecil']],
            ['Pengisian kas kecil (pindah dana, tidak dihitung sebagai pengeluaran)', '', (float) $ringkasan['mutasi_kas_kecil']],
            ['', '', ''],
            ['PER KATEGORI', '', ''],
        ]);

        foreach ($this->laporan['per_kategori'] as $kategori) {
            $rows->push([
                $kategori['label'] . ($kategori['mutasi'] ? ' (pindah dana)' : ''),
                (int) $kategori['jumlah'],
                (float) $kategori['total'],
            ]);
        }

        return $rows;
    }

    private function sheetRincian(string $judul, string $subjudul): SheetTabelTautan
    {
        $rows    = collect();
        $dokumen = [];
        $bukti   = [];

        foreach (array_values($this->laporan['data']) as $i => $baris) {
            $tautanDokumen = AsalFrontend::tautan($this->asalFrontend, $baris['link_dokumen']);

            $rows->push([
                $i + 1,
                date('d/m/Y', strtotime($baris['tanggal'])),
                $baris['nomor'],
                $baris['label_kategori'] . ($baris['mutasi'] ? ' (pindah dana)' : ''),
                $baris['label_sumber_dana'],
                (string) ($baris['penerima'] ?? ''),
                (string) ($baris['keterangan'] ?? ''),
                (float) $baris['nominal'],
                (string) ($baris['oleh'] ?? ''),
                $baris['label_dokumen'],
                $baris['url_bukti'] !== null ? 'Lihat bukti' : '',
            ]);
            $dokumen[$i] = $tautanDokumen;
            $bukti[$i]   = $baris['url_bukti'];
        }

        $rows->push(['', '', '', '', '', '', 'TOTAL PENGELUARAN', (float) $this->laporan['rincian']['total'], '', '', '']);

        return new SheetTabelTautan(
            'Rincian',
            'RINCIAN PENGELUARAN' . $judul,
            $subjudul,
            ['No', 'Tanggal', 'Nomor', 'Kategori', 'Sumber Dana', 'Penerima', 'Keterangan', 'Nominal', 'Diproses Oleh', 'Dokumen', 'Bukti'],
            $rows,
            [8],
            [self::KOLOM_DOKUMEN => $dokumen, self::KOLOM_BUKTI => $bukti],
        );
    }
}
