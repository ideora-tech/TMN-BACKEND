<?php

declare(strict_types=1);

namespace App\Modules\Kasbon\Resources;

use App\Modules\Kasbon\KasbonService;
use Illuminate\Http\Resources\Json\JsonResource;

class KasbonResource extends JsonResource
{
    public function toArray($request): array
    {
        $status  = KasbonService::statusDari($this->resource);
        $sisa    = KasbonService::sisaDari($this->resource);
        $cicilan = (float) $this->cicilan_per_periode;
        $bolehPelunasan = in_array($request?->user()?->kode_peran, KasbonService::PERAN_PELUNASAN, true);

        $data = [
            'id_kasbon'            => $this->id_kasbon,
            'nomor_kasbon'         => $this->nomor_kasbon,
            'tanggal'              => $this->tanggal,
            'id_karyawan'          => $this->id_karyawan,
            'nama_karyawan'        => $this->nama_karyawan ?? null,
            'nik'                  => $this->nik ?? null,
            'nama_jabatan'         => $this->nama_jabatan ?? null,
            'karyawan_aktif'       => (int) ($this->karyawan_aktif ?? 0) === 1,
            'nominal'              => (float) $this->nominal,
            'cicilan_per_periode'  => $cicilan,
            'mulai_potong'         => substr((string) $this->mulai_potong, 0, 7),
            'keperluan'            => $this->keperluan,
            'nama_bank'            => $this->nama_bank,
            'nomor_rekening'       => $this->nomor_rekening,
            'saldo_awal'           => (int) $this->saldo_awal === 1,
            'terbayar'             => round((float) ($this->terbayar ?? 0), 2),
            'sisa'                 => $sisa,
            'sisa_potongan'        => $cicilan > 0 ? (int) ceil($sisa / $cicilan) : 0,
            'status'               => $status,
            'id_pengajuan'         => $this->id_pengajuan,
            'nomor_pengajuan'      => $this->nomor_pengajuan ?? null,
            'status_pengajuan'     => $this->status_pengajuan ?? null,
            'alasan_ditolak'       => $this->alasan_ditolak ?? null,
            'tanggal_transfer'     => $this->tanggal_transfer ?? null,
            'bisa_diubah'          => KasbonService::bisaDiubah($this->resource),
            'bisa_ubah_cicilan'    => KasbonService::bisaUbahCicilan($this->resource),
            'bisa_catat_pelunasan' => $bolehPelunasan && $status === KasbonService::STATUS_BERJALAN,
            'dibuat_pada'          => $this->dibuat_pada,
            'diubah_pada'          => $this->diubah_pada,
        ];

        if (isset($this->resource->pembayaran)) {
            $data['pembayaran'] = array_map(static fn (object $p) => [
                'id_kasbon_pembayaran' => $p->id_kasbon_pembayaran,
                'tanggal'              => $p->tanggal,
                'nominal'              => (float) $p->nominal,
                'sumber'               => $p->sumber,
                'id_periode'           => $p->id_periode,
                'nama_periode'         => $p->nama_periode,
                'keterangan'           => $p->keterangan,
                'tercatat_pemasukan'   => $p->id_pemasukan !== null,
                'bisa_dihapus'         => $bolehPelunasan && $p->sumber === 'manual',
            ], $this->resource->pembayaran);
        }

        if (isset($this->resource->perubahan_cicilan)) {
            $data['perubahan_cicilan'] = array_map(static fn (object $r) => [
                'id_riwayat_cicilan' => $r->id_riwayat_cicilan,
                'waktu'              => $r->dibuat_pada,
                'oleh'               => $r->oleh,
                'cicilan_lama'       => (float) $r->cicilan_lama,
                'cicilan_baru'       => (float) $r->cicilan_baru,
                'mulai_potong_lama'  => substr((string) $r->mulai_potong_lama, 0, 7),
                'mulai_potong_baru'  => substr((string) $r->mulai_potong_baru, 0, 7),
                'alasan'             => $r->alasan,
            ], $this->resource->perubahan_cicilan);
        }

        return $data;
    }
}
