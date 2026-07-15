<?php

declare(strict_types=1);

namespace App\Modules\Lokasi\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLokasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_lokasi' => ['required', 'string', 'max:150'],
            'alamat'      => ['sometimes', 'nullable', 'string'],
            'kota'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
