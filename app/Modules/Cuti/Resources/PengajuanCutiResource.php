<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PengajuanCutiResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_pengajuan'     => $this->id_pengajuan,
            'id_karyawan'      => $this->id_karyawan,
            'id_supir'         => $this->id_supir,
            'tipe_orang'       => $this->id_karyawan !== null ? 'karyawan' : 'supir',
            'nama_orang'       => $this->nama_orang ?? null,
            'id_jenis_cuti'    => $this->id_jenis_cuti,
            'nama_jenis'       => $this->nama_jenis ?? null,
            'mengurangi_saldo' => (bool) ($this->mengurangi_saldo ?? false),
            'tanggal_mulai'    => $this->tanggal_mulai,
            'tanggal_selesai'  => $this->tanggal_selesai,
            'jumlah_hari'      => (int) $this->jumlah_hari,
            'alasan'           => $this->alasan,
            'status'           => $this->status,
            'diproses_pada'    => $this->diproses_pada,
            'catatan_proses'   => $this->catatan_proses,
            'dibuat_pada'      => $this->dibuat_pada,
        ];
    }
}
