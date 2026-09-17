<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Resources;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\Resources\Json\JsonResource;

class SparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_sparepart'           => $this->id_sparepart,
            'id_perusahaan'          => $this->id_perusahaan,
            'kode'                   => $this->kode,
            'nama'                   => $this->nama,
            'serial_number'          => $this->serial_number,
            'merek'                  => $this->merek,
            'tahun'                  => $this->tahun !== null ? (int) $this->tahun : null,
            'id_kategori_sparepart'  => $this->id_kategori_sparepart,
            'nama_kategori_sparepart' => $this->nama_kategori_sparepart ?? null,
            'satuan'                 => $this->satuan,
            'harga_standar'          => (float) $this->harga_standar,
            'stok'                   => (int) $this->stok,
            'aktif'                  => (bool) $this->aktif,
            'harga_beli_terakhir'    => $this->hargaBeliTerakhir(),
            'foto'                   => array_map(fn ($f) => [
                'id_foto'   => $f->id_foto,
                'url_file'  => PenyimpananBerkas::url($f->url_file),
                'nama_asli' => $f->nama_asli,
            ], $this->foto ?? []),
            'dibuat_pada'            => $this->dibuat_pada,
            'diubah_pada'            => $this->diubah_pada,
        ];
    }

    private function hargaBeliTerakhir(): ?array
    {
        $row = $this->harga_beli_terakhir ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'harga'           => (float) $row->harga,
            'tanggal'         => (string) $row->tanggal,
            'id_pembelian'    => $row->id_pembelian,
            'nomor_pengajuan' => $row->nomor_pengajuan,
            'nama_supplier'   => $row->nama_supplier,
        ];
    }
}
