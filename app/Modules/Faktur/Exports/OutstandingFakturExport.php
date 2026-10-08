<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Exports;

use App\Modules\PermintaanPembelian\Exports\SheetLaporanPengadaan;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class OutstandingFakturExport implements WithMultipleSheets
{
    private const LABEL_KELOMPOK = [
        'belum_jatuh_tempo' => 'Belum jatuh tempo',
        'hari_1_30'         => 'Terlambat 1-30 hari',
        'hari_31_60'        => 'Terlambat 31-60 hari',
        'di_atas_60'        => 'Terlambat > 60 hari',
    ];

    public function __construct(private readonly array $laporan) {}

    public function sheets(): array
    {
        $perTanggal = 'Per ' . date('d/m/Y');
        $ringkasan = $this->laporan['ringkasan'];

        $ringkasanRows = collect([
            ['Jumlah invoice outstanding', (int) $ringkasan['jumlah_invoice']],
            ['Total tagihan', (float) $ringkasan['total_tagihan']],
            ['Sudah dibayar', (float) $ringkasan['total_terbayar']],
            ['Total outstanding', (float) $ringkasan['total_outstanding']],
            ['Lewat jatuh tempo', (float) $ringkasan['lewat_jatuh_tempo']['nominal']],
            ['Jatuh tempo dalam 7 hari', (float) $ringkasan['jatuh_tempo_7_hari']['nominal']],
        ]);
        foreach (self::LABEL_KELOMPOK as $kunci => $label) {
            $ringkasanRows->push([$label, (float) $ringkasan['aging'][$kunci]['nominal']]);
        }

        $klienRows = collect($this->laporan['per_klien'])->map(fn ($k) => [
            $k['nama_klien'], (int) $k['jumlah'], (float) $k['outstanding'], (float) $k['terlambat'],
        ]);

        $invoiceRows = collect($this->laporan['data'])->map(fn ($r) => [
            $r['nomor_faktur'],
            $r['nama_klien'] ?? '-',
            $r['nama_proyek'] ?? '-',
            $r['tanggal_faktur'] ?? '-',
            $r['jatuh_tempo'] ?? '-',
            (int) $r['hari_terlambat'],
            self::LABEL_KELOMPOK[$r['kelompok']] ?? $r['kelompok'],
            (float) $r['total'],
            (float) $r['terbayar'],
            (float) $r['sisa'],
        ]);

        return [
            new SheetLaporanPengadaan('Ringkasan', 'PIUTANG KLIEN OUTSTANDING', $perTanggal, ['Keterangan', 'Nilai'], $ringkasanRows),
            new SheetLaporanPengadaan('Per Klien', 'OUTSTANDING PER KLIEN', $perTanggal, ['Klien', 'Jumlah Invoice', 'Outstanding', 'Lewat Jatuh Tempo'], $klienRows),
            new SheetLaporanPengadaan('Per Invoice', 'OUTSTANDING PER INVOICE', $perTanggal, ['Nomor Invoice', 'Klien', 'Proyek', 'Tanggal', 'Jatuh Tempo', 'Hari Terlambat', 'Kelompok Umur', 'Total', 'Dibayar', 'Sisa'], $invoiceRows),
        ];
    }
}
