<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Resources;

use App\Modules\KasKecil\KasKecilService;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class KasKecilPengisianResource extends JsonResource
{
    public function toArray($request): array
    {
        $status = KasKecilService::statusPengisian($this->resource);

        return [
            'id_pengisian'       => $this->id_pengisian,
            'nomor_pengisian'    => $this->nomor_pengisian,
            'tanggal'            => substr((string) $this->tanggal, 0, 10),
            'nominal'            => (float) $this->nominal,
            'keterangan'         => $this->keterangan,
            'id_rekening_perusahaan' => $this->id_rekening_perusahaan ?? null,
            'nama_bank'          => $this->nama_bank,
            'nomor_rekening'     => $this->nomor_rekening,
            'atas_nama_rekening' => $this->atas_nama_rekening,
            'status'             => $status,
            'id_pengajuan'       => $this->id_pengajuan,
            'nomor_pengajuan'    => $this->nomor_pengajuan ?? null,
            'status_pengajuan'   => $this->status_pengajuan ?? null,
            'alasan_ditolak'     => $this->alasan_ditolak ?? null,
            'tanggal_transfer'   => $this->tanggal_transfer ?? null,
            'url_bukti_transfer' => $status === KasKecilService::STATUS_SELESAI
                ? PenyimpananBerkas::url($this->url_bukti_transfer ?? null)
                : null,
            'dibuat_oleh_nama'   => $this->dibuat_oleh_nama ?? null,
            'bisa_diubah'        => KasKecilService::pengisianBisaDiubah($this->resource),
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
