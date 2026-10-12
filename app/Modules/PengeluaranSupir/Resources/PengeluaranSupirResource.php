<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir\Resources;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\PengeluaranSupir\PengeluaranSupirService;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class PengeluaranSupirResource extends JsonResource
{
    public function toArray($request): array
    {
        $status  = PengeluaranSupirService::statusDari($this->resource);
        $dibayar = $status === PengeluaranSupirService::STATUS_DIBAYAR;

        return [
            'id_pengeluaran_supir' => $this->id_pengeluaran_supir,
            'nomor'                => $this->nomor,
            'jenis'                => $this->jenis,
            'label_jenis'          => ArusKasService::labelKategori($this->jenis),
            'tanggal'              => substr((string) $this->tanggal, 0, 10),
            'nominal'              => (float) $this->nominal,
            'id_supir'             => $this->id_supir,
            'nama_supir'           => $this->nama_supir ?? null,
            'id_armada'            => $this->id_armada,
            'id_armada_vendor'     => $this->id_armada_vendor,
            'nopol'                => $this->nopol,
            'unit_vendor'          => $this->id_armada_vendor !== null,
            'keterangan'           => $this->keterangan,
            'nama_bank'            => $this->nama_bank,
            'nomor_rekening'       => $this->nomor_rekening,
            'atas_nama_rekening'   => $this->atas_nama_rekening,
            'status'               => $status,
            'label_status'         => PengeluaranSupirService::LABEL_STATUS[$status],
            'alasan_ditolak'       => $status === PengeluaranSupirService::STATUS_DITOLAK ? ($this->alasan_ditolak ?? null) : null,
            'tanggal_dibayar'      => $dibayar && $this->tanggal_transfer !== null ? substr((string) $this->tanggal_transfer, 0, 10) : null,
            'url_bukti_bayar'      => $dibayar ? PenyimpananBerkas::url($this->url_bukti_bayar ?? null) : null,
            'id_pengajuan'         => $this->id_pengajuan,
            'nomor_pengajuan'      => $this->nomor_pengajuan ?? null,
            'bisa_dibatalkan'      => PengeluaranSupirService::bisaDibatalkan($this->resource),
            'foto'                 => array_values(array_filter(array_map(
                static fn (string $url) => PenyimpananBerkas::url($url),
                $this->foto ?? [],
            ))),
            'dibuat_pada'          => $this->dibuat_pada !== null ? Carbon::parse($this->dibuat_pada)->toIso8601String() : null,
        ];
    }
}
