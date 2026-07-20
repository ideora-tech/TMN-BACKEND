<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProyekRuteResource extends JsonResource
{
    public function toArray($request): array
    {
        $komponen = [
            $this->estimasi_tol ?? null,
            $this->estimasi_bbm ?? null,
            $this->estimasi_uang_jalan ?? null,
            $this->estimasi_biaya_lain ?? null,
        ];
        $semuaKosong = collect($komponen)->every(fn ($k) => $k === null);

        return [
            'id_proyek_rute'     => $this->id_proyek_rute,
            'id_proyek'          => $this->id_proyek,
            'id_rute'            => $this->id_rute,
            'kode_rute'          => $this->kode_rute ?? null,
            'nama_rute'          => $this->nama_rute ?? null,
            'asal'               => $this->asal ?? null,
            'tujuan'             => $this->tujuan ?? null,
            'id_jenis_kendaraan' => $this->id_jenis_kendaraan,
            'nama_jenis'         => $this->nama_jenis ?? null,
            'id_tarif_rute'      => $this->id_tarif_rute,
            'harga_penawaran'    => $this->harga_penawaran !== null ? (float) $this->harga_penawaran : null,
            'estimasi_biaya'     => $semuaKosong ? null : array_sum(array_map(fn ($k) => (float) ($k ?? 0), $komponen)),
            'keterangan'         => $this->keterangan,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
