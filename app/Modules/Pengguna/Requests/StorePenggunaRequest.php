<?php

declare(strict_types=1);

namespace App\Modules\Pengguna\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePenggunaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_peran'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'username'             => ['required', 'string', 'max:100'],
            'email'                => ['required', 'email', 'max:150'],
            'password'             => ['required', 'string', 'min:8'],
            'id_karyawan'          => ['sometimes', 'nullable', 'string', 'size:36'],
            'aktif'                => ['sometimes', 'boolean'],
            'harus_ganti_password' => ['sometimes', 'boolean'],
        ];
    }
}
