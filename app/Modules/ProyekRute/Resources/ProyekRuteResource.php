<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProyekRuteResource extends JsonResource
{
    public function toArray($request): array
    {
        $komponen = [
            $this->uang_jalan ?? null,
            $this->estimasi_tol ?? null,
            $this->estimasi_bbm ?? null,
            $this->estimasi_biaya_lain ?? null,
        ];
        $semuaKosong = collect($komponen)->every(fn ($k) => $k === null);

        return [
            'id_proyek_rute'      => $this->id_proyek_rute,
            'id_proyek'           => $this->id_proyek,
            'id_rute'             => $this->id_rute,
            'kode_rute'           => $this->kode_rute ?? null,
            'nama_rute'           => $this->nama_rute ?? null,
            'asal'                => $this->asal ?? null,
            'tujuan'              => $this->tujuan ?? null,
            'id_jenis_kendaraan'  => $this->id_jenis_kendaraan,
            'nama_jenis'          => $this->nama_jenis ?? null,
            'harga_penawaran'     => $this->harga_penawaran !== null ? (float) $this->harga_penawaran : null,
            'estimasi_ritase'     => (int) $this->estimasi_ritase,
            'subtotal'            => $this->harga_penawaran !== null
                ? (float) $this->harga_penawaran * (int) $this->estimasi_ritase
                : null,
            'uang_jalan'          => $this->uang_jalan !== null ? (float) $this->uang_jalan : null,
            'estimasi_tol'        => $this->estimasi_tol !== null ? (float) $this->estimasi_tol : null,
            'estimasi_bbm'        => $this->estimasi_bbm !== null ? (float) $this->estimasi_bbm : null,
            'estimasi_biaya_lain' => $this->estimasi_biaya_lain !== null ? (float) $this->estimasi_biaya_lain : null,
            'estimasi_biaya'      => $semuaKosong ? null : array_sum(array_map(fn ($k) => (float) ($k ?? 0), $komponen)),
            'keterangan'          => $this->keterangan,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
