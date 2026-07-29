<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_vendor'       => ['required', 'string', 'max:50', Rule::unique('vendor', 'kode_vendor')],
            'nama_vendor'       => ['required', 'string', 'max:200'],
            'jenis_vendor'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'pic_nama'          => ['sometimes', 'nullable', 'string', 'max:150'],
            'email'             => ['sometimes', 'nullable', 'email', 'max:150'],
            'telepon'           => ['sometimes', 'nullable', 'string', 'max:30'],
            'alamat'            => ['sometimes', 'nullable', 'string'],
            'npwp'              => ['sometimes', 'nullable', 'string', 'max:30'],
            'tanggal_bergabung' => ['sometimes', 'nullable', 'date'],
            'aktif'             => ['sometimes', 'boolean'],
        ];
    }
}
