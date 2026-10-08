<?php

declare(strict_types=1);

namespace App\Modules\UangJalan\Resources;

use App\Modules\UangJalan\UangJalanService;
use Illuminate\Http\Resources\Json\JsonResource;

class UangJalanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_uang_jalan'       => $this->id_uang_jalan,
            'nomor_uang_jalan'    => $this->nomor_uang_jalan,
            'tanggal'             => $this->tanggal,
            'nama_driver'         => $this->nama_driver,
            'tipe_driver'         => $this->tipe_driver,
            'id_supir'            => $this->id_supir,
            'id_supir_vendor'     => $this->id_supir_vendor,
            'id_armada'           => $this->id_armada,
            'id_armada_vendor'    => $this->id_armada_vendor,
            'id_vendor'           => $this->id_vendor,
            'id_rute'             => $this->id_rute,
            'id_proyek'           => $this->id_proyek,
            'kode_proyek'         => $this->kode_proyek,
            'nama_proyek'         => $this->nama_proyek,
            'id_penugasan'        => $this->id_penugasan,
            'nama_vendor'         => $this->nama_vendor,
            'nopol'               => $this->nopol,
            'rute'                => $this->rute,
            'tol_per_trip'        => (float) $this->tol_per_trip,
            'bbm_per_trip'        => (float) $this->bbm_per_trip,
            'biaya_lain_per_trip' => (float) $this->biaya_lain_per_trip,
            'uang_jalan_per_trip' => (float) $this->uang_jalan_per_trip,
            'jumlah_trip'         => (int) $this->jumlah_trip,
            'nominal'             => (float) $this->nominal,
            'catatan'             => $this->catatan,
            'nomor_rekening'      => $this->nomor_rekening,
            'nama_bank'           => $this->nama_bank,
            'id_pengajuan'        => $this->id_pengajuan,
            'nomor_pengajuan'     => $this->nomor_pengajuan ?? null,
            'status_pengajuan'    => $this->status_pengajuan ?? null,
            'alasan_ditolak'      => $this->alasan_ditolak ?? null,
            'tanggal_transfer'    => $this->tanggal_transfer ?? null,
            'bisa_diubah'         => in_array($this->status_pengajuan ?? null, UangJalanService::STATUS_BISA_DIUBAH, true),
            'dibuat_pada'         => $this->dibuat_pada,
            'diubah_pada'         => $this->diubah_pada,
        ];
    }
}
