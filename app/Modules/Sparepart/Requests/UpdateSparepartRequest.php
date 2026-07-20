<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode'                   => ['sometimes', 'string', 'max:50'],
            'nama'                   => ['sometimes', 'string', 'max:150'],
            'id_kategori_sparepart'  => ['sometimes', 'nullable', 'string', 'exists:kategori_sparepart,id_kategori_sparepart,dihapus_pada,NULL'],
            'satuan'                 => ['sometimes', 'string', 'max:30'],
            'harga_standar'          => ['sometimes', 'numeric', 'min:0'],
            'aktif'                  => ['sometimes', 'boolean'],
        ];
    }
}
