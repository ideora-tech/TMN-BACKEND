<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Exports;

use App\Modules\Faktur\FakturModel;
use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;

class FakturDetailExport implements FromCollection, WithHeadings, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    public function __construct(private readonly FakturModel $faktur) {}

    public function judulLaporan(): string
    {
        return 'INVOICE ' . $this->faktur->nomor_faktur;
    }

    public function subjudulLaporan(): string
    {
        $tgl = fn ($v) => $v ? $v->format('d/m/Y') : '-';

        return sprintf(
            'Klien: %s | Proyek: %s | Tanggal: %s | Jatuh Tempo: %s | Status: %s',
            $this->faktur->nama_klien ?? '-',
            $this->faktur->nama_proyek ?? '-',
            $tgl($this->faktur->tanggal_faktur),
            $tgl($this->faktur->jatuh_tempo),
            strtoupper((string) $this->faktur->status),
        );
    }

    public function collection(): Collection
    {
        $rows = $this->faktur->items->values()->map(fn ($item, $i) => [
            $i + 1,
            $item->deskripsi,
            (float) $item->qty,
            (float) $item->harga_satuan,
            (float) $item->subtotal,
        ]);

        if ($rows->isEmpty()) {
            $rows->push([1, 'Tagihan sesuai invoice ' . $this->faktur->nomor_faktur, 1, (float) $this->faktur->total, (float) $this->faktur->total]);
        }

        $rows->push(['', 'TOTAL', '', '', (float) $this->faktur->total]);

        return $rows;
    }

    public function headings(): array
    {
        return ['No', 'Deskripsi', 'Qty', 'Harga Satuan', 'Subtotal'];
    }
}
