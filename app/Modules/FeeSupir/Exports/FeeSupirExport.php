<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir\Exports;

use App\Modules\PermintaanPembelian\Exports\SheetLaporanPengadaan;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FeeSupirExport implements WithMultipleSheets
{
    public function __construct(private readonly array $laporan) {}

    public function sheets(): array
    {
        $subjudul = 'Periode ' . $this->tanggal($this->laporan['dari']) . ' s.d. ' . $this->tanggal($this->laporan['sampai']);
        if (!empty($this->laporan['search'])) {
            $subjudul .= ' — Pencarian: ' . $this->laporan['search'];
        }

        $nomor = 0;
        $rekap = collect($this->laporan['rekap'])->map(function (array $r) use (&$nomor) {
            return [++$nomor, $r['nama_supir'] ?? '-', $r['telepon'] ?? '-', $r['jumlah_trip'], $r['total']];
        });
        $rekap->push(['', 'TOTAL', '', $rekap->sum(3), round((float) $rekap->sum(4), 2)]);

        $nomor = 0;
        $rincian = collect($this->laporan['rincian'])->map(function (array $f) use (&$nomor) {
            $rute = $f['nama_rute'] ?? '';
            if (!empty($f['asal']) || !empty($f['tujuan'])) {
                $rute = trim($rute . ' (' . ($f['asal'] ?? '-') . ' → ' . ($f['tujuan'] ?? '-') . ')');
            }

            return [
                ++$nomor,
                $this->tanggal($f['tanggal']),
                $f['nama_supir'] ?? '-',
                trim(($f['kode_proyek'] ?? '') . ' ' . ($f['nama_proyek'] ?? '')) ?: '-',
                $rute !== '' ? $rute : '-',
                $f['nopol'] ?? '-',
                $f['waktu_selesai'] !== null ? date('d/m/Y H:i', strtotime((string) $f['waktu_selesai'])) : '-',
                $f['nominal'],
            ];
        });
        $rincian->push(['', '', '', '', '', '', 'TOTAL', round((float) $rincian->sum(7), 2)]);

        return [
            new SheetLaporanPengadaan('Rekap per Supir', 'REKAP FEE SUPIR ON CALL', $subjudul, ['No', 'Supir', 'Telepon', 'Jumlah Trip', 'Total Fee'], $rekap),
            new SheetLaporanPengadaan('Rincian Trip', 'RINCIAN FEE SUPIR ON CALL PER TRIP', $subjudul, ['No', 'Tanggal', 'Supir', 'Proyek', 'Rute', 'Nopol', 'Selesai', 'Fee'], $rincian),
        ];
    }

    private function tanggal(string $tanggal): string
    {
        return date('d/m/Y', strtotime($tanggal));
    }
}
