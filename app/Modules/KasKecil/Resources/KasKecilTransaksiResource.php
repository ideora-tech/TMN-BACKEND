<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Resources;

use App\Modules\KasKecil\KasKecilService;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class KasKecilTransaksiResource extends JsonResource
{
    public function toArray($request): array
    {
        $pengeluaran = $this->jenis === KasKecilService::JENIS_PENGELUARAN;

        return [
            'id_transaksi'    => $this->id_transaksi,
            'nomor_transaksi' => $this->nomor_transaksi,
            'tanggal'         => substr((string) $this->tanggal, 0, 10),
            'arah'            => $this->arah,
            'jenis'           => $this->jenis,
            'label_jenis'     => KasKecilService::LABEL_JENIS[$this->jenis] ?? $this->jenis,
            'kategori'        => $this->kategori,
            'label_kategori'  => KasKecilService::labelKategori($this->kategori),
            'nominal'         => (float) $this->nominal,
            'penerima'        => $this->penerima,
            'keterangan'      => $this->keterangan,
            'url_bukti'       => PenyimpananBerkas::url($this->url_bukti),
            'id_pengisian'    => $this->id_pengisian,
            'nomor_pengisian' => $this->nomor_pengisian ?? null,
            'saldo'           => isset($this->resource->saldo) ? (float) $this->resource->saldo : null,
            'dicatat_oleh'    => $this->dicatat_oleh ?? null,
            'bisa_diubah'     => $pengeluaran,
            'dibuat_pada'     => $this->dibuat_pada,
        ];
    }
}
