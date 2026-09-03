<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PenawaranResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_penawaran'     => $this->id_penawaran,
            'id_perusahaan'    => $this->id_perusahaan,
            'id_klien'         => $this->id_klien,
            'nama_klien'       => $this->nama_klien,
            'nomor_penawaran'  => $this->nomor_penawaran,
            'judul'            => $this->judul,
            'nilai_penawaran'  => $this->nilai_penawaran !== null ? (float) $this->nilai_penawaran : null,
            'status'           => $this->status,
            'tipe_harga'       => $this->tipe_harga,
            'tanggal_penawaran'=> $this->tanggal_penawaran,
            'tanggal_berlaku'  => $this->tanggal_berlaku,
            'catatan'          => $this->catatan,
            'alasan_ditolak_internal' => $this->alasan_ditolak_internal,
            'id_proyek'        => $this->id_proyek,
            'proyek_status'    => $this->proyek_status ?? null,
            'kode_proyek'      => $this->kode_proyek ?? null,
            'id_penawaran_induk' => $this->id_penawaran_induk,
            'aktif'            => (bool) $this->aktif,
            'dibuat_pada'      => $this->dibuat_pada,
            'diubah_pada'      => $this->diubah_pada,
            'items'            => $this->when(
                $this->relationLoaded('items'),
                fn () => PenawaranItemResource::collection($this->getRelation('items')),
            ),
        ];
    }
}