<?php

declare(strict_types=1);

namespace App\Modules\Barang\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKategoriBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['required', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
