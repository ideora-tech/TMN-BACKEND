<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJenisKendaraanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_jenis'       => ['sometimes', 'string', 'max:50'],
            'nama_jenis'       => ['sometimes', 'string', 'max:150'],
            'kapasitas_muatan' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'aktif'            => ['sometimes', 'boolean'],
        ];
    }
}
