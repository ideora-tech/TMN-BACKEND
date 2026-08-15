<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class KonsolidasiKlienExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents
{
    private const PPN_PERSEN = 1.1;
    private const PPH_PERSEN = 2.0;
    private const MAX_DROP_KOLOM = 6;
    private const KOLOM_SEBELUM_BIAYA = 15;

    private array $namaBiaya;

    public function __construct(
        private readonly string $namaKlien,
        private readonly string $periode,
        private readonly Collection $trips,
    ) {
        $seen = [];
        foreach ($this->trips as $t) {
            foreach ($t['biaya_tagihan'] ?? [] as $b) {
                $nama = trim((string) ($b['nama_biaya'] ?? ''));
                if ($nama === '') {
                    continue;
                }
                $kunci = mb_strtolower($nama);
                if (!isset($seen[$kunci])) {
                    $seen[$kunci] = $nama;
                }
            }
        }
        $this->namaBiaya = $seen === [] ? ['-'] : array_values($seen);
    }

    public function judulLaporan(): string
    {
        return 'KONSOLIDASI KLIEN ' . mb_strtoupper($this->namaKlien);
    }

    public function subjudulLaporan(): string
    {
        return $this->periode;
    }

    private function jumlahKolom(): int
    {
        return self::KOLOM_SEBELUM_BIAYA + count($this->namaBiaya) + 1;
    }

    public function headings(): array
    {
        return [
            array_merge(
                ['No', 'Tanggal', 'Nama Project', 'Type Truck', 'Nopol', 'Nama Driver', 'Origin',
                 'Destination', '', '', '', '', '',
                 'DO No', 'Cost (Rp)', 'Biaya Tambahan (Rp)'],
                array_fill(0, count($this->namaBiaya) - 1, ''),
                ['Total (Rp)'],
            ),
            array_merge(
                ['', '', '', '', '', '', '',
                 'Trip 1', 'Trip 2', 'Trip 3', 'Trip 4', 'Trip 5', 'Trip 6',
                 '', ''],
                $this->namaBiaya,
                [''],
            ),
        ];
    }

    private function kolomDrop(array $titikDrop, ?string $tujuan): array
    {
        $drops = $titikDrop !== [] ? array_values($titikDrop) : array_values(array_filter([$tujuan]));
        if (count($drops) > self::MAX_DROP_KOLOM) {
            $sisa = array_slice($drops, self::MAX_DROP_KOLOM - 1);
            $drops = array_slice($drops, 0, self::MAX_DROP_KOLOM - 1);
            $drops[] = implode(' / ', $sisa);
        }
        return array_pad($drops, self::MAX_DROP_KOLOM, '');
    }

    private function pecahBiaya(array $biayaTagihan): array
    {
        $perNama = array_fill_keys(array_map(fn ($n) => mb_strtolower($n), $this->namaBiaya), 0.0);
        foreach ($biayaTagihan as $b) {
            $kunci = mb_strtolower(trim((string) ($b['nama_biaya'] ?? '')));
            if ($kunci === '' || !array_key_exists($kunci, $perNama)) {
                continue;
            }
            $perNama[$kunci] += (float) ($b['nominal'] ?? 0);
        }
        return array_values($perNama);
    }

    public function array(): array
    {
        $totalKeseluruhan = 0.0;

        $rows = $this->trips->values()->map(function ($t, $i) use (&$totalKeseluruhan) {
            $biayaPerKolom = $this->pecahBiaya($t['biaya_tagihan'] ?? []);
            $cost  = (float) ($t['tarif']['harga'] ?? 0);
            $total = $cost + array_sum($biayaPerKolom);
            $totalKeseluruhan += $total;

            return array_merge(
                [
                    $i + 1,
                    $t['tanggal'],
                    trim(($t['kode_proyek'] ?? '') . ' ' . ($t['nama_proyek'] ?? '')) ?: '-',
                    $t['jenis_kendaraan'] ?? '-',
                    $t['nopol'] ?? '-',
                    $t['supir_nama'] ?? '-',
                    $t['asal'] ?? '-',
                ],
                $this->kolomDrop($t['titik_drop'] ?? [], $t['tujuan'] ?? null),
                ['', $cost],
                array_map(fn ($v) => $v ?: '', $biayaPerKolom),
                [$total],
            );
        })->all();

        $ppn = round($totalKeseluruhan * self::PPN_PERSEN / 100, 2);
        $pph = round($totalKeseluruhan * self::PPH_PERSEN / 100, 2);
        $kosong = array_fill(0, $this->jumlahKolom() - 2, '');

        $rows[] = array_merge($kosong, ['TOTAL', $totalKeseluruhan]);
        $rows[] = array_merge($kosong, ['PPN ' . self::PPN_PERSEN . '%', $ppn]);
        $rows[] = array_merge($kosong, ['PPH ' . self::PPH_PERSEN . '%', $pph]);
        $rows[] = array_merge($kosong, ['SUBTOTAL', $totalKeseluruhan + $ppn - $pph]);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $kolomTotal      = $this->jumlahKolom();
                $kolomBiayaAwal  = self::KOLOM_SEBELUM_BIAYA + 1;
                $kolomBiayaAkhir = $kolomTotal - 1;
                $huruf   = fn (int $i) => Coordinate::stringFromColumnIndex($i);
                $lastCol = $huruf($kolomTotal);

                $sheet->insertNewRowBefore(1, 2);

                $sheet->setCellValue('A1', $this->judulLaporan());
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->setCellValue('A2', $this->subjudulLaporan());
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->getStyle('A2')->getFont()->setBold(true);
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'N', 'O'] as $kolom) {
                    $sheet->mergeCells("{$kolom}3:{$kolom}4");
                }
                $sheet->mergeCells('H3:M3');
                $sheet->mergeCells("{$lastCol}3:{$lastCol}4");
                if ($kolomBiayaAkhir > $kolomBiayaAwal) {
                    $sheet->mergeCells($huruf($kolomBiayaAwal) . '3:' . $huruf($kolomBiayaAkhir) . '3');
                }

                $sheet->getStyle("A3:{$lastCol}4")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'D9D9D9'],
                    ],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $lastRow = $sheet->getHighestRow();
                $sheet->getStyle("A3:{$lastCol}{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                    ],
                ]);

                $labelCol = $huruf($kolomTotal - 1);
                for ($r = $lastRow - 3; $r <= $lastRow; $r++) {
                    $sheet->getStyle("{$labelCol}{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
                }
                $sheet->getStyle("O5:{$lastCol}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
            },
        ];
    }
}
