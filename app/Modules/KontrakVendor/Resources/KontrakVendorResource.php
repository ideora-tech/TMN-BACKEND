<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KontrakVendorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kontrak_vendor' => $this->id_kontrak_vendor,
            'id_perusahaan'     => $this->id_perusahaan,
            'id_vendor'         => $this->id_vendor,
            'vendor'            => $this->vendor_nama !== null ? [
                'id_vendor'   => $this->id_vendor,
                'nama_vendor' => $this->vendor_nama,
            ] : null,
            'id_kontrak_induk'  => $this->id_kontrak_induk,
            'nomor_kontrak_induk' => $this->nomor_kontrak_induk ?? null,
            'id_permintaan'     => $this->id_permintaan ?? null,
            'nomor_permintaan'  => $this->nomor_permintaan ?? null,
            'jumlah_turunan'    => (int) ($this->jumlah_turunan ?? 0),
            'turunan'           => $this->when(
                isset($this->turunan),
                fn () => $this->turunan,
            ),
            'total_nilai_turunan' => $this->when(
                isset($this->turunan),
                fn () => (float) $this->total_nilai_turunan,
            ),
            'id_proyek'         => $this->id_proyek,
            'nama_proyek'       => $this->nama_proyek ?? null,
            'nomor_kontrak'     => $this->nomor_kontrak,
            'mekanisme'         => $this->mekanisme,
            'jenis_layanan'     => $this->jenis_layanan,
            'nilai_kontrak'     => (float) $this->nilai_kontrak,
            'rate'              => $this->rate,
            'satuan'            => $this->satuan,
            'pajak_persen'      => $this->pajak_persen,
            'termin_pembayaran_hari' => $this->termin_pembayaran_hari,
            'jumlah_trip'       => $this->jumlah_trip !== null ? (int) $this->jumlah_trip : null,
            'jumlah_hari'       => $this->jumlah_hari !== null ? (int) $this->jumlah_hari : null,
            'tanggal_mulai'     => $this->tanggal_mulai,
            'tanggal_selesai'   => $this->tanggal_selesai,
            'status'            => $this->status,
            'alasan_ditolak_internal' => $this->alasan_ditolak_internal ?? null,
            'dibuat_pada'       => $this->dibuat_pada,
            'diubah_pada'       => $this->diubah_pada,
        ];
    }
}
