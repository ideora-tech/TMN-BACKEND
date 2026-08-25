<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Exports;

use App\Modules\Faktur\FakturModel;
use App\Support\Exports\DenganGayaLaporan;
use App\Support\Terbilang;
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

        $info = sprintf(
            'Klien: %s | Proyek: %s | Tanggal: %s | Jatuh Tempo: %s | Status: %s',
            $this->faktur->nama_klien ?? '-',
            $this->faktur->nama_proyek ?? '-',
            $tgl($this->faktur->tanggal_faktur),
            $tgl($this->faktur->jatuh_tempo),
            strtoupper((string) $this->faktur->status),
        );

        if (!empty($this->faktur->nomor_penawaran)) {
            $info .= ' | Ref. Penawaran: ' . $this->faktur->nomor_penawaran;
        }

        return $info;
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
        } elseif (!empty($this->faktur->persen_pajak)) {
            $subtotal = (float) $this->faktur->items->sum('subtotal');
            $rows->push(['', 'Subtotal', '', '', $subtotal]);
            $namaPajak = $this->faktur->nama_pajak ?: 'Pajak';
            $rows->push(['', "{$namaPajak} ({$this->faktur->persen_pajak}%)", '', '', (float) $this->faktur->total - $subtotal]);
        }

        $rows->push(['', 'TOTAL', '', '', (float) $this->faktur->total]);
        $rows->push(['', 'Terbilang: ' . Terbilang::rupiah((float) $this->faktur->total), '', '', '']);

        return $rows;
    }

    public function headings(): array
    {
        return ['No', 'Deskripsi', 'Qty', 'Harga Satuan', 'Subtotal'];
    }
}
