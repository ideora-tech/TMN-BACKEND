<?php

declare(strict_types=1);

namespace App\Modules\PembelianSparepart\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LaporanPembelianExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $laporan,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    private function periode(): string
    {
        if ($this->dari && $this->sampai) {
            return 'Periode: ' . date('d/m/Y', strtotime($this->dari)) . ' — ' . date('d/m/Y', strtotime($this->sampai));
        }
        return 'Semua Periode';
    }

    public function sheets(): array
    {
        $ringkasan   = $this->laporan['ringkasan'];
        $perBulan    = collect($this->laporan['per_bulan']);
        $perKategori = collect($this->laporan['per_kategori']);
        $perArmada   = collect($this->laporan['per_armada']);

        $ringkasanRows = collect([
            ['Total Estimasi',   (float) $ringkasan['total_estimasi']],
            ['Total Aktual',     (float) $ringkasan['total_aktual']],
            ['Selisih',          (float) $ringkasan['selisih']],
            ['Jumlah Pembelian', (int) $ringkasan['jumlah']],
        ]);

        $bulanRows = $perBulan->map(fn ($b) => [
            date('M Y', strtotime($b->bulan . '-01')),
            (float) $b->total_estimasi,
            (float) $b->total_aktual,
            (int) $b->jumlah,
        ]);
        if ($perBulan->isNotEmpty()) {
            $bulanRows->push([
                'TOTAL',
                $perBulan->sum(fn ($b) => (float) $b->total_estimasi),
                $perBulan->sum(fn ($b) => (float) $b->total_aktual),
                $perBulan->sum(fn ($b) => (int) $b->jumlah),
            ]);
        }

        $kategoriRows = $perKategori->map(fn ($k) => [
            $k->kategori,
            (float) $k->total_aktual,
        ]);
        if ($perKategori->isNotEmpty()) {
            $kategoriRows->push(['TOTAL', $perKategori->sum(fn ($k) => (float) $k->total_aktual)]);
        }

        $armadaRows = $perArmada->map(fn ($a) => [
            $a->nopol,
            (float) $a->total_aktual,
            (int) $a->jumlah,
        ]);
        if ($perArmada->isNotEmpty()) {
            $armadaRows->push([
                'TOTAL',
                $perArmada->sum(fn ($a) => (float) $a->total_aktual),
                $perArmada->sum(fn ($a) => (int) $a->jumlah),
            ]);
        }

        $periode = $this->periode();

        return [
            new SheetLaporanPembelian('Ringkasan', 'LAPORAN PEMBELIAN SPAREPART', $periode, ['Keterangan', 'Nilai'], $ringkasanRows),
            new SheetLaporanPembelian('Per Bulan', 'PEMBELIAN PER BULAN', $periode, ['Bulan', 'Total Estimasi', 'Total Aktual', 'Jumlah'], $bulanRows),
            new SheetLaporanPembelian('Per Kategori', 'PEMBELIAN PER KATEGORI SPAREPART', $periode, ['Kategori', 'Total Aktual'], $kategoriRows),
            new SheetLaporanPembelian('Per Armada', 'PEMBELIAN PER ARMADA', $periode, ['Nopol', 'Total Aktual', 'Jumlah Pembelian'], $armadaRows),
        ];
    }
}
