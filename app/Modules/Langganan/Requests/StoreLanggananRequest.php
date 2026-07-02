<?php

declare(strict_types=1);

namespace App\Modules\Langganan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLanggananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_paket'       => ['required', 'string', 'max:50'],
            'maks_karyawan'    => ['required', 'integer', 'min:0'],
            'mulai_pada'       => ['nullable', 'date'],
            'kedaluwarsa_pada' => ['nullable', 'date'],
            'aktif'            => ['sometimes', 'boolean'],
        ];
    }
}
