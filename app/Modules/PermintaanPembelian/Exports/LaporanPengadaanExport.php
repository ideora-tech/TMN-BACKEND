<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LaporanPengadaanExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $laporan,
        private readonly ?string $dari = null,
        private readonly ?string $sampai = null,
    ) {}

    public static function labelPeriode(?string $dari, ?string $sampai): string
    {
        if ($dari && $sampai) {
            return 'Periode ' . date('d/m/Y', strtotime($dari)) . ' — ' . date('d/m/Y', strtotime($sampai));
        }
        if ($dari) {
            return 'Mulai ' . date('d/m/Y', strtotime($dari));
        }
        if ($sampai) {
            return 'Sampai ' . date('d/m/Y', strtotime($sampai));
        }
        return 'Semua Periode';
    }

    private function periode(): string
    {
        return self::labelPeriode($this->dari, $this->sampai);
    }

    public function sheets(): array
    {
        $periode = $this->periode();
        $ringkasan = $this->laporan['ringkasan'];
        $perBulan = collect($this->laporan['per_bulan']);
        $perTipe = collect($this->laporan['per_tipe']);
        $perKategori = collect($this->laporan['per_kategori']);
        $perDepartemen = collect($this->laporan['per_departemen']);
        $perSupplier = collect($this->laporan['per_supplier']);
        $perArmada = collect($this->laporan['per_armada']);
        $tanpaArmada = $this->laporan['sparepart_tanpa_armada'] ?? ['total_aktual' => 0, 'jumlah' => 0];
        $aset = collect($this->laporan['aset']);
        $menungguLama = collect($this->laporan['menunggu_lama']);

        $ringkasanRows = collect([
            ['Total Estimasi', (float) $ringkasan['total_estimasi']],
            ['Total Aktual', (float) $ringkasan['total_aktual']],
            ['Selisih', (float) $ringkasan['selisih']],
            ['Jumlah Pembelian', (int) $ringkasan['jumlah']],
            ['Menunggu Diproses', (int) $ringkasan['menunggu_diproses']],
            ['Rata-rata Lead Time (hari)', $ringkasan['rata_lead_time_hari'] !== null ? (float) $ringkasan['rata_lead_time_hari'] : '-'],
        ]);

        $bulanRows = $perBulan->map(fn ($b) => [
            date('M Y', strtotime($b['bulan'] . '-01')), (float) $b['umum'], (float) $b['sparepart'], (float) $b['aset'], (float) $b['total'], (int) $b['jumlah'],
        ]);
        if ($perBulan->isNotEmpty()) {
            $bulanRows->push([
                'TOTAL',
                $perBulan->sum(fn ($b) => (float) $b['umum']),
                $perBulan->sum(fn ($b) => (float) $b['sparepart']),
                $perBulan->sum(fn ($b) => (float) $b['aset']),
                $perBulan->sum(fn ($b) => (float) $b['total']),
                $perBulan->sum(fn ($b) => (int) $b['jumlah']),
            ]);
        }

        $tipeRows = $perTipe->map(fn ($t) => [$t['label'], (float) $t['total_aktual'], (int) $t['jumlah']]);

        $kategoriRows = $perKategori->map(fn ($k) => [$k['kategori'], (float) $k['total_aktual']]);

        $departemenRows = $perDepartemen->map(fn ($d) => [$d['departemen'], (float) $d['total_aktual'], (int) $d['jumlah']]);

        $supplierRows = $perSupplier->map(fn ($s) => [$s['supplier'], (float) $s['total_aktual'], (int) $s['jumlah']]);

        $armadaRows = $perArmada->map(fn ($a) => [$a['nopol'], (float) $a['total_aktual'], (int) $a['jumlah']]);
        if ((int) $tanpaArmada['jumlah'] > 0) {
            $armadaRows->push(['Tanpa armada (stok)', (float) $tanpaArmada['total_aktual'], (int) $tanpaArmada['jumlah']]);
        }

        $asetRows = $aset->map(fn ($a) => [
            $a['nomor_permintaan'], $a['judul'], $a['tanggal_pembelian'], (int) $a['unit_total'], (int) $a['unit_terdaftar'],
            (float) $a['total_aktual'], (float) $a['terbayar'], (float) $a['sisa'], $a['status'],
        ]);

        $menungguRows = $menungguLama->map(fn ($m) => [
            $m['nomor_permintaan'], $m['judul'], $m['tipe'], $m['status'], $m['tanggal_permintaan'], (int) $m['umur_hari'], $m['username_pengaju'] ?? '-',
        ]);

        return [
            new SheetLaporanPengadaan('Ringkasan', 'LAPORAN PENGADAAN', $periode, ['Keterangan', 'Nilai'], $ringkasanRows),
            new SheetLaporanPengadaan('Per Bulan', 'PENGADAAN PER BULAN', $periode, ['Bulan', 'Umum', 'Spare Part', 'Aset', 'Total', 'Jumlah'], $bulanRows),
            new SheetLaporanPengadaan('Per Tipe', 'PENGADAAN PER TIPE', $periode, ['Tipe', 'Total Aktual', 'Jumlah'], $tipeRows),
            new SheetLaporanPengadaan('Per Kategori', 'PENGADAAN PER KATEGORI', $periode, ['Kategori', 'Total Aktual'], $kategoriRows),
            new SheetLaporanPengadaan('Per Departemen', 'PENGADAAN PER DEPARTEMEN', $periode, ['Departemen', 'Total Aktual', 'Jumlah'], $departemenRows),
            new SheetLaporanPengadaan('Per Supplier', 'PENGADAAN PER SUPPLIER', $periode, ['Supplier', 'Total Aktual', 'Jumlah'], $supplierRows),
            new SheetLaporanPengadaan('Per Armada', 'SPARE PART PER ARMADA', $periode, ['Nopol', 'Total Aktual', 'Jumlah'], $armadaRows),
            new SheetLaporanPengadaan('Aset', 'PENGADAAN ASET', $periode, ['Nomor PR', 'Judul', 'Tgl Beli', 'Unit', 'Terdaftar', 'Total Aktual', 'Terbayar', 'Sisa', 'Status'], $asetRows),
            new SheetLaporanPengadaan('Menunggu Lama', 'PR MENUNGGU DIPROSES', $periode, ['Nomor PR', 'Judul', 'Tipe', 'Status', 'Tgl Permintaan', 'Umur (hari)', 'Pengaju'], $menungguRows),
        ];
    }
}
