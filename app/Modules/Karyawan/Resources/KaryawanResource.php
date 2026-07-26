<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KaryawanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_karyawan'        => $this->id_karyawan,
            'id_perusahaan'      => $this->id_perusahaan,
            'nik'                => $this->nik,
            'nik_ktp'            => $this->nik_ktp,
            'nama_karyawan'      => $this->nama_karyawan,
            'email'              => $this->email,
            'telepon'            => $this->telepon,
            'jenis_kelamin'      => $this->jenis_kelamin,
            'tempat_lahir'       => $this->tempat_lahir,
            'tanggal_lahir'      => $this->tanggal_lahir,
            'alamat_ktp'         => $this->alamat_ktp,
            'alamat_domisili'    => $this->alamat_domisili,
            'status_pernikahan'  => $this->status_pernikahan,
            'jumlah_tanggungan'  => (int) $this->jumlah_tanggungan,
            'status_ptkp'        => $this->status_ptkp,
            'npwp'               => $this->npwp,
            'nama_bank'          => $this->nama_bank,
            'nomor_rekening'     => $this->nomor_rekening,
            'atas_nama_rekening' => $this->atas_nama_rekening,
            'ikut_bpjs_kesehatan'       => (bool) $this->ikut_bpjs_kesehatan,
            'no_bpjs_kesehatan'         => $this->no_bpjs_kesehatan,
            'ikut_bpjs_ketenagakerjaan' => (bool) $this->ikut_bpjs_ketenagakerjaan,
            'no_bpjs_ketenagakerjaan'   => $this->no_bpjs_ketenagakerjaan,
            'kontak_darurat_nama'     => $this->kontak_darurat_nama,
            'kontak_darurat_telepon'  => $this->kontak_darurat_telepon,
            'kontak_darurat_hubungan' => $this->kontak_darurat_hubungan,
            'pendidikan_terakhir'     => $this->pendidikan_terakhir,
            'tanggal_masuk'      => $this->tanggal_masuk,
            'status_kepegawaian' => $this->status_kepegawaian,
            'gaji_pokok'         => (float) $this->gaji_pokok,
            'aktif'              => (bool) $this->aktif,
            'jabatan'            => $this->jabatan_nama !== null ? [
                'id_jabatan'   => $this->id_jabatan,
                'nama_jabatan' => $this->jabatan_nama,
            ] : null,
            'lokasi'             => $this->lokasi_nama !== null ? [
                'id_lokasi'   => $this->id_lokasi,
                'nama_lokasi' => $this->lokasi_nama,
            ] : null,
            'dibuat_pada'        => $this->dibuat_pada,
            'diubah_pada'        => $this->diubah_pada,
        ];
    }
}
