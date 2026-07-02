<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJenisKendaraanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_jenis'       => ['required', 'string', 'max:50'],
            'nama_jenis'       => ['required', 'string', 'max:150'],
            'kapasitas_muatan' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'aktif'            => ['sometimes', 'boolean'],
        ];
    }
}
