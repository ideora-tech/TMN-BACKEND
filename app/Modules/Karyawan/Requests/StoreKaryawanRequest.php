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
            'nama_karyawan'      => ['required', 'string', 'max:200'],
            'email'              => ['sometimes', 'nullable', 'email', 'max:150'],
            'telepon'            => ['sometimes', 'nullable', 'string', 'max:30'],
            'jenis_kelamin'      => ['sometimes', 'nullable', 'string', 'in:L,P'],
            'tanggal_lahir'      => ['sometimes', 'nullable', 'date'],
            'tanggal_masuk'      => ['sometimes', 'nullable', 'date'],
            'status_kepegawaian' => ['sometimes', 'string', 'in:tetap,kontrak,magang,paruh_waktu'],
            'gaji_pokok'         => ['sometimes', 'numeric', 'min:0'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
