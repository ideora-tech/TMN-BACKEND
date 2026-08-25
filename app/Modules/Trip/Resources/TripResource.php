<?php

declare(strict_types=1);

namespace App\Modules\Trip\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_trip'         => $this->id_trip,
            'id_jadwal'       => $this->id_jadwal,
            'id_penugasan'    => $this->id_penugasan,
            'id_proyek'       => $this->id_proyek,
            'kode_proyek'     => $this->kode_proyek,
            'nama_proyek'     => $this->nama_proyek,
            'nama_klien'      => $this->nama_klien,
            'rute'            => $this->rute,
            'waktu_berangkat' => $this->waktu_berangkat,
            'supir_nama'      => $this->supir_nama,
            'armada_nopol'    => $this->armada_nopol,
            'sumber'          => $this->sumber ?? 'internal',
            'vendor_nama'     => $this->vendor_nama,
            'mekanisme'       => $this->mekanisme,
            'waktu_checkin'   => $this->waktu_checkin,
            'waktu_checkout'  => $this->waktu_checkout,
            'status'          => $this->status,
            'catatan'         => $this->catatan,
            'uang_jalan_alokasi' => $this->uang_jalan_alokasi !== null ? (float) $this->uang_jalan_alokasi : null,
            'titik_drop'         => $this->titik_drop ?? [],
            'sudah_difakturkan'  => (bool) ($this->sudah_difakturkan ?? false),
            'punya_laporan'      => (bool) ($this->punya_laporan ?? false),
            'ditugaskan_oleh_nama'  => $this->ditugaskan_oleh_nama,
            'ditugaskan_oleh_peran' => $this->ditugaskan_oleh_peran,
            'pengajuan_uang_jalan' => $this->pengajuan_uang_jalan ?? null,
            'dibuat_pada'     => $this->dibuat_pada,
            'diubah_pada'     => $this->diubah_pada,
        ];
    }
}
