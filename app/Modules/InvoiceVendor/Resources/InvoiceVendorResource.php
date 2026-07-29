<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_invoice_vendor'  => $this->id_invoice_vendor,
            'id_perusahaan'      => $this->id_perusahaan,
            'id_vendor'          => $this->id_vendor,
            'nama_vendor'        => $this->nama_vendor ?? null,
            'vendor'             => ($this->nama_vendor ?? null) !== null ? [
                'id_vendor'   => $this->id_vendor,
                'nama_vendor' => $this->nama_vendor,
            ] : null,
            'id_kontrak_vendor'  => $this->id_kontrak_vendor,
            'nomor_invoice'      => $this->nomor_invoice,
            'tanggal_invoice'    => $this->tanggal_invoice?->toDateString(),
            'jatuh_tempo'        => $this->jatuh_tempo?->toDateString(),
            'no_po'              => $this->no_po,
            'no_do'              => $this->no_do,
            'dpp'                => (float) $this->dpp,
            'ppn'                => (float) $this->ppn,
            'pph'                => (float) $this->pph,
            'total'              => (float) $this->total,
            'status'             => $this->status,
            'catatan_verifikasi' => $this->catatan_verifikasi,
            'diverifikasi_oleh'  => $this->diverifikasi_oleh,
            'diverifikasi_pada'  => $this->diverifikasi_pada?->toIso8601String(),
            'status_pembayaran'  => $this->status_pembayaran,
            'keterangan'         => $this->keterangan,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
