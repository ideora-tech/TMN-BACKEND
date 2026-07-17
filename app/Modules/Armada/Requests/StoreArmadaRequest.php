<?php

declare(strict_types=1);

namespace App\Modules\Armada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArmadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_kendaraan'  => ['sometimes', 'nullable', 'string', 'max:36', 'exists:jenis_kendaraan,id_jenis_kendaraan,dihapus_pada,NULL'],
            'id_vendor'           => ['sometimes', 'nullable', 'string', 'max:36'],
            'nopol'               => ['required', 'string', 'max:20'],
            'merk'                => ['sometimes', 'nullable', 'string', 'max:100'],
            'model'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'tahun'               => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'kepemilikan'         => ['sometimes', 'in:internal,vendor'],
            'status'              => ['sometimes', 'string', 'in:tersedia,digunakan,perawatan,tidak_aktif'],
            'aktif'               => ['sometimes', 'boolean'],
            'nomor_rangka'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'nomor_mesin'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'warna'               => ['sometimes', 'nullable', 'string', 'max:50'],
            'jenis_bahan_bakar'   => ['sometimes', 'nullable', 'in:solar,bensin,gas,listrik,hybrid'],
            'kapasitas_muatan_kg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tanggal_beli'        => ['sometimes', 'nullable', 'date'],
            'harga_beli'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kondisi_beli'        => ['sometimes', 'nullable', 'in:baru,bekas'],
            'foto'                => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'keterangan'          => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'                 => 'Status tidak valid',
            'jenis_bahan_bakar.in'      => 'Jenis bahan bakar tidak valid',
            'kondisi_beli.in'           => 'Kondisi beli tidak valid',
            'foto.mimes'                => 'Foto harus berformat JPG, PNG, atau WEBP',
            'foto.max'                  => 'Ukuran foto maksimal 5 MB',
            'id_jenis_kendaraan.exists' => 'Jenis kendaraan tidak ditemukan',
        ];
    }
}
