<?php
namespace App\Modules\Rute\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class RuteResource extends JsonResource {
    public function toArray($request): array {
        return [
            'id_rute'               => $this->id_rute,
            'id_perusahaan'         => $this->id_perusahaan,
            'kode_rute'             => $this->kode_rute,
            'nama_rute'             => $this->nama_rute,
            'asal'                  => $this->asal,
            'tujuan'                => $this->tujuan,
            'estimasi_jarak_km'     => $this->estimasi_jarak_km ? (float) $this->estimasi_jarak_km : null,
            'estimasi_durasi_menit' => $this->estimasi_durasi_menit,
            'keterangan'            => $this->keterangan,
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}