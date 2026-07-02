<?php

declare(strict_types=1);

namespace App\Modules\Langganan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLanggananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_paket'       => ['sometimes', 'string', 'max:50'],
            'maks_karyawan'    => ['sometimes', 'integer', 'min:0'],
            'mulai_pada'       => ['sometimes', 'nullable', 'date'],
            'kedaluwarsa_pada' => ['sometimes', 'nullable', 'date'],
            'aktif'            => ['sometimes', 'boolean'],
        ];
    }
}
