<?php

declare(strict_types=1);

namespace App\Modules\Jabatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJabatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_departemen' => ['sometimes', 'nullable', 'string', 'uuid'],
            'id_peran'      => ['sometimes', 'nullable', 'string', 'uuid'],
            'kode_jabatan'  => ['required', 'string', 'max:50'],
            'nama_jabatan'  => ['required', 'string', 'max:150'],
            'is_supir'      => ['sometimes', 'boolean'],
            'level'         => ['sometimes', 'integer', 'min:0'],
            'tunjangan_jabatan' => ['sometimes', 'numeric', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
