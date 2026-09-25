<?php

declare(strict_types=1);

namespace App\Modules\Barang\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'               => ['required', 'string', 'max:150'],
            'id_kategori_barang' => ['sometimes', 'nullable', 'string', 'max:36'],
            'satuan'             => ['sometimes', 'string', 'max:30'],
            'harga_standar'      => ['sometimes', 'numeric', 'min:0'],
            'stok_minimum'       => ['sometimes', 'integer', 'min:0'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
