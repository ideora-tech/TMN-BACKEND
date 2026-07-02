<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_vendor' => ['required', 'string', 'max:50'],
            'nama_vendor' => ['required', 'string', 'max:200'],
            'email'       => ['sometimes', 'nullable', 'email', 'max:150'],
            'telepon'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'alamat'      => ['sometimes', 'nullable', 'string'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
