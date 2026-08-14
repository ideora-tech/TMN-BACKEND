<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class PengajuanPengeluaranResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_pengajuan'      => $this->id_pengajuan,
            'id_perusahaan'     => $this->id_perusahaan,
            'id_trip'           => $this->id_trip,
            'id_perawatan'      => $this->id_perawatan,
            'id_armada_perawatan' => $this->id_armada_perawatan,
            'id_pembelian'      => $this->id_pembelian,
            'id_periode'        => $this->id_periode,
            'nomor_pengajuan'   => $this->nomor_pengajuan,
            'kategori'          => $this->kategori,
            'nominal'           => (float) $this->nominal,
            'tanggal_pengajuan' => $this->tanggal_pengajuan,
            'penerima'          => $this->penerima,
            'keterangan'        => $this->keterangan,
            'status'            => $this->status,
            'alasan_ditolak'    => $this->alasan_ditolak,
            'dicek_oleh'        => $this->dicek_oleh,
            'dicek_pada'        => $this->dicek_pada,
            'disetujui_oleh'    => $this->disetujui_oleh,
            'disetujui_pada'    => $this->disetujui_pada,
            'ditransfer_oleh'   => $this->ditransfer_oleh,
            'ditransfer_pada'   => $this->ditransfer_pada,
            'tanggal_transfer'  => $this->tanggal_transfer,
            'url_bukti'         => PenyimpananBerkas::url($this->url_bukti),
            'dibuat_pada'       => $this->dibuat_pada,
            'diubah_pada'       => $this->diubah_pada,
        ];
    }
}
