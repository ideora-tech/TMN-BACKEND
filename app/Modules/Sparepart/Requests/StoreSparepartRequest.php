<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode'                   => ['required', 'string', 'max:50'],
            'nama'                   => ['required', 'string', 'max:150'],
            'id_kategori_sparepart'  => ['sometimes', 'nullable', 'string', 'exists:kategori_sparepart,id_kategori_sparepart,dihapus_pada,NULL'],
            'satuan'                 => ['sometimes', 'string', 'max:30'],
            'harga_standar'          => ['sometimes', 'numeric', 'min:0'],
            'aktif'                  => ['sometimes', 'boolean'],
        ];
    }
}
