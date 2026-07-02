<?php

declare(strict_types=1);

namespace App\Modules\Pengguna\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePenggunaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_peran'    => ['sometimes', 'string', 'size:36'],
            'username'    => ['sometimes', 'string', 'max:100'],
            'email'       => ['sometimes', 'email', 'max:150'],
            'password'    => ['sometimes', 'string', 'min:8'],
            'id_karyawan' => ['nullable', 'string', 'size:36'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
