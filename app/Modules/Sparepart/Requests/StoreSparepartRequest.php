<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use App\Modules\Sparepart\SparepartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'serial_number'          => ['required', 'string', 'max:100'],
            'merek'                  => ['sometimes', 'nullable', 'string', 'max:100'],
            'tahun'                  => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:' . (now()->year + 1)],
            'id_kategori_sparepart'  => ['sometimes', 'nullable', 'string', 'exists:kategori_sparepart,id_kategori_sparepart,dihapus_pada,NULL'],
            'satuan'                 => ['sometimes', 'string', Rule::in(SparepartService::SATUAN_VALID)],
            'harga_standar'          => ['sometimes', 'numeric', 'min:0'],
            'aktif'                  => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Serial number wajib diisi',
            'satuan.in'              => 'Satuan harus pcs, set, atau liter',
            'tahun.integer'          => 'Tahun tidak valid',
            'tahun.min'              => 'Tahun tidak valid',
            'tahun.max'              => 'Tahun tidak valid',
        ];
    }
}
