<?php

declare(strict_types=1);

namespace App\Modules\Barang\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuatCepatBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'               => ['required', 'string', 'max:150'],
            'satuan'             => ['required', 'string', 'max:30'],
            'id_kategori_barang' => ['nullable', 'string', 'max:36'],
            'harga_standar'      => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
