<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PembelianSparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        $items = collect($this->items ?? [])->map(fn ($i) => [
            'id_item'        => $i->id_item,
            'id_sparepart'   => $i->id_sparepart,
            'nama_sparepart' => $i->nama_sparepart,
            'qty'            => (int) $i->qty,
            'harga_estimasi' => (float) $i->harga_estimasi,
            'harga_aktual'   => $i->harga_aktual !== null ? (float) $i->harga_aktual : null,
            'selisih'        => $i->harga_aktual !== null ? (float) $i->harga_aktual - (float) $i->harga_estimasi : null,
        ])->all();

        return [
            'id_pembelian'           => $this->id_pembelian,
            'nomor_pengajuan'        => $this->nomor_pengajuan,
            'id_supplier'            => $this->id_supplier,
            'nama_supplier'          => $this->nama_supplier ?? null,
            'id_perawatan'           => $this->id_perawatan,
            'nopol_armada'           => $this->nopol_armada ?? null,
            'status'                 => $this->status,
            'alasan_ditolak'         => $this->alasan_ditolak,
            'disetujui_manager_pada' => $this->disetujui_manager_pada,
            'disetujui_finance_pada' => $this->disetujui_finance_pada,
            'total_estimasi'         => (float) $this->total_estimasi,
            'total_aktual'           => $this->total_aktual !== null ? (float) $this->total_aktual : null,
            'selisih'                => $this->total_aktual !== null ? (float) $this->total_aktual - (float) $this->total_estimasi : null,
            'tanggal_pengajuan'      => $this->tanggal_pengajuan,
            'tanggal_pembelian'      => $this->tanggal_pembelian,
            'tanggal_pembayaran'     => $this->tanggal_pembayaran,
            'keterangan'             => $this->keterangan,
            'items'                  => $items,
            'bukti'                  => $this->bukti ?? [],
            'dibuat_pada'            => $this->dibuat_pada,
        ];
    }
}
