<?php

declare(strict_types=1);

namespace App\Modules\Jabatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJabatanRequest extends FormRequest
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
            'kode_jabatan'  => ['sometimes', 'string', 'max:50'],
            'nama_jabatan'  => ['sometimes', 'string', 'max:150'],
            'level'         => ['sometimes', 'integer', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
