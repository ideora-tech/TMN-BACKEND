<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Exports;

use App\Support\Exports\DenganGayaLaporan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KontrakVendorDetailExport implements FromCollection, WithHeadings, ShouldAutoSize, WithEvents
{
    use DenganGayaLaporan;

    private const MEKANISME_LABEL = ['unit_only' => 'Unit Only', 'unit_driver' => 'Unit + Driver', 'full' => 'All In'];
    private const STATUS_LABEL = ['draft' => 'Draft', 'menunggu_approval' => 'Menunggu Approval', 'aktif' => 'Aktif', 'selesai' => 'Selesai', 'batal' => 'Batal'];

    public function __construct(private readonly array $data) {}

    public function judulLaporan(): string
    {
        $nomor = $this->data['kontrak']->nomor_kontrak;
        return 'KONTRAK VENDOR' . ($nomor ? ' ' . $nomor : '');
    }

    public function subjudulLaporan(): string
    {
        $k = $this->data['kontrak'];
        $tgl = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '-';

        return sprintf(
            'Vendor: %s | Mekanisme: %s | Nilai: %s | Periode: %s - %s | Status: %s',
            $this->data['namaVendor'] ?? '-',
            self::MEKANISME_LABEL[$k->mekanisme] ?? $k->mekanisme,
            $k->nilai_kontrak ? 'Rp ' . number_format((float) $k->nilai_kontrak, 0, ',', '.') : '-',
            $tgl($k->tanggal_mulai),
            $tgl($k->tanggal_selesai),
            self::STATUS_LABEL[$k->status] ?? (string) $k->status,
        );
    }

    public function headings(): array
    {
        return $this->data['paket']
            ? ['No', 'Nopol', 'Merk', 'Jenis', 'Kapasitas', 'Driver', 'Telepon Driver', 'No. SIM Driver', 'Habis STNK', 'Habis KIR']
            : ['No', 'Nopol', 'Merk', 'Jenis', 'Kapasitas', 'Habis STNK', 'Habis KIR'];
    }

    public function collection(): Collection
    {
        $paket = $this->data['paket'];
        $tgl = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '-';
        $lebar = $paket ? 10 : 7;

        $rows = collect($this->data['units'])->values()->map(function ($u, $i) use ($paket, $tgl) {
            $baris = [$i + 1, $u->nopol, $u->merk ?? '-', $u->jenis ?? '-', $u->kapasitas ?? '-'];
            if ($paket) {
                $baris[] = $u->driver_nama ?? '-';
                $baris[] = $u->driver_telepon ?? '-';
                $baris[] = $u->driver_no_sim ?? '-';
            }
            $baris[] = $tgl($u->masa_berlaku_stnk);
            $baris[] = $tgl($u->masa_berlaku_kir);
            return $baris;
        });

        $cadangan = collect($this->data['cadangan']);
        if ($paket && $cadangan->isNotEmpty()) {
            $rows->push(array_fill(0, $lebar, ''));
            $rows->push(array_pad(['DRIVER CADANGAN'], $lebar, ''));
            foreach ($cadangan->values() as $i => $s) {
                $rows->push(array_pad([$i + 1, $s->nama, $s->telepon ?? '-', $s->no_sim ?? '-'], $lebar, ''));
            }
        }

        return $rows;
    }
}
