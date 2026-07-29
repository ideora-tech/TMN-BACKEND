<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKaryawanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jabatan'         => ['sometimes', 'nullable', 'string', 'uuid'],
            'id_lokasi'          => ['sometimes', 'nullable', 'string', 'uuid'],
            'nik'                => ['required', 'string', 'max:50'],
            'nik_ktp'            => ['sometimes', 'nullable', 'string', 'max:20'],
            'nama_karyawan'      => ['required', 'string', 'max:200'],
            'email'              => ['sometimes', 'nullable', 'email', 'max:150'],
            'telepon'            => ['sometimes', 'nullable', 'string', 'max:30'],
            'jenis_kelamin'      => ['sometimes', 'nullable', 'string', 'in:L,P'],
            'tempat_lahir'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'tanggal_lahir'      => ['sometimes', 'nullable', 'date'],
            'alamat_ktp'         => ['sometimes', 'nullable', 'string'],
            'alamat_domisili'    => ['sometimes', 'nullable', 'string'],
            'status_pernikahan'  => ['sometimes', 'nullable', 'string', 'in:belum_menikah,menikah,cerai'],
            'jumlah_tanggungan'  => ['sometimes', 'integer', 'min:0', 'max:10'],
            'status_ptkp'        => ['sometimes', 'nullable', 'string', 'in:TK/0,TK/1,TK/2,TK/3,K/0,K/1,K/2,K/3'],
            'npwp'               => ['sometimes', 'nullable', 'string', 'max:30'],
            'nama_bank'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'nomor_rekening'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'atas_nama_rekening' => ['sometimes', 'nullable', 'string', 'max:150'],
            'ikut_bpjs_kesehatan'       => ['sometimes', 'boolean'],
            'no_bpjs_kesehatan'         => ['sometimes', 'nullable', 'string', 'max:30'],
            'ikut_bpjs_ketenagakerjaan' => ['sometimes', 'boolean'],
            'no_bpjs_ketenagakerjaan'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'override_persen_bpjs_kesehatan' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'override_persen_bpjs_jht'       => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'override_persen_bpjs_jp'        => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'override_plafon_bpjs_kesehatan' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kontak_darurat_nama'     => ['sometimes', 'nullable', 'string', 'max:150'],
            'kontak_darurat_telepon'  => ['sometimes', 'nullable', 'string', 'max:30'],
            'kontak_darurat_hubungan' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pendidikan_terakhir'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'tanggal_masuk'      => ['sometimes', 'nullable', 'date'],
            'status_kepegawaian' => ['sometimes', 'string', 'in:tetap,kontrak,magang,paruh_waktu'],
            'gaji_pokok'         => ['sometimes', 'numeric', 'min:0'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
