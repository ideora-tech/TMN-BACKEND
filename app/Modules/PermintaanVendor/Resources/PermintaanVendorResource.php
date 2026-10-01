<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class PermintaanVendorResource extends JsonResource
{
    private function lamaPemenuhanMenit(): ?int
    {
        if ($this->disetujui_pada === null) {
            return null;
        }

        $akhir = match ($this->status) {
            'disetujui', 'diproses'   => Carbon::now(),
            'dikontrakkan', 'selesai' => $this->dikontrakkan_pada,
            'ditolak_pengadaan'       => $this->ditolak_pengadaan_pada,
            default                   => null,
        };
        if ($akhir === null) {
            return null;
        }

        return max(0, (int) round(Carbon::parse($this->disetujui_pada)->diffInMinutes(Carbon::parse($akhir))));
    }

    public function toArray($request): array
    {
        return [
            'id_permintaan'        => $this->id_permintaan,
            'id_perusahaan'        => $this->id_perusahaan,
            'nomor_permintaan'     => $this->nomor_permintaan,
            'id_proyek'            => $this->id_proyek,
            'nama_proyek'          => $this->nama_proyek ?? null,
            'id_penawaran'         => $this->id_penawaran,
            'nomor_penawaran'      => $this->nomor_penawaran ?? null,
            'id_jenis_kendaraan'   => $this->id_jenis_kendaraan,
            'nama_jenis_kendaraan' => $this->nama_jenis_kendaraan ?? null,
            'jumlah_unit'          => (int) $this->jumlah_unit,
            'unit_diminta'         => $this->unit_diminta ?? [],
            'mekanisme'            => $this->mekanisme,
            'periode_dari'         => $this->periode_dari,
            'periode_sampai'       => $this->periode_sampai,
            'catatan'              => $this->catatan,
            'harga_penawaran'      => $this->harga_penawaran !== null ? (float) $this->harga_penawaran : null,
            'status'               => $this->status,
            'alasan_ditolak'       => $this->alasan_ditolak,
            'id_kontrak_vendor'    => $this->id_kontrak_vendor,
            'nomor_kontrak'        => $this->nomor_kontrak ?? null,
            'diproses_oleh'        => $this->diproses_oleh,
            'nama_diproses_oleh'   => $this->nama_diproses_oleh ?? null,
            'diproses_pada'        => $this->diproses_pada,
            'alasan_batal'         => $this->alasan_batal,
            'disetujui_pada'       => $this->disetujui_pada,
            'dikontrakkan_pada'    => $this->dikontrakkan_pada,
            'alasan_tolak_pengadaan' => $this->alasan_tolak_pengadaan,
            'ditolak_pengadaan_oleh' => $this->ditolak_pengadaan_oleh,
            'nama_ditolak_pengadaan_oleh' => $this->nama_ditolak_pengadaan_oleh ?? null,
            'ditolak_pengadaan_pada' => $this->ditolak_pengadaan_pada,
            'lama_pemenuhan_menit' => $this->lamaPemenuhanMenit(),
            'pemenuhan_berjalan'   => $this->disetujui_pada !== null && in_array($this->status, ['disetujui', 'diproses'], true),
            'dibuat_oleh'          => $this->dibuat_oleh,
            'dibuat_pada'          => $this->dibuat_pada,
            'diubah_pada'          => $this->diubah_pada,
        ];
    }
}
