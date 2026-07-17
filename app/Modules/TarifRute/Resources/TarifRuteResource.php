<?php

declare(strict_types=1);

namespace App\Modules\TarifRute\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TarifRuteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_tarif_rute'       => $this->id_tarif_rute,
            'id_perusahaan'       => $this->id_perusahaan,
            'id_rute'             => $this->id_rute,
            'id_jenis_kendaraan'  => $this->id_jenis_kendaraan,
            'id_klien'            => $this->id_klien,
            'kode_rute'           => $this->kode_rute ?? null,
            'nama_rute'           => $this->nama_rute ?? null,
            'asal'                => $this->asal ?? null,
            'tujuan'              => $this->tujuan ?? null,
            'nama_jenis'          => $this->nama_jenis ?? null,
            'nama_klien'          => $this->nama_klien ?? null,
            'harga'               => (float) $this->harga,
            'estimasi_tol'        => $this->estimasi_tol !== null ? (float) $this->estimasi_tol : null,
            'estimasi_bbm'        => $this->estimasi_bbm !== null ? (float) $this->estimasi_bbm : null,
            'estimasi_uang_jalan' => $this->estimasi_uang_jalan !== null ? (float) $this->estimasi_uang_jalan : null,
            'estimasi_biaya_lain' => $this->estimasi_biaya_lain !== null ? (float) $this->estimasi_biaya_lain : null,
            'tanggal_mulai'       => $this->tanggal_mulai,
            'tanggal_berakhir'    => $this->tanggal_berakhir,
            'keterangan'          => $this->keterangan,
            'aktif'               => (bool) $this->aktif,
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
