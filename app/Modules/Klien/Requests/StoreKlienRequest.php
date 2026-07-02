<?php

declare(strict_types=1);

namespace App\Modules\Klien\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKlienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_klien'  => ['required', 'string', 'max:50'],
            'nama_klien'  => ['required', 'string', 'max:200'],
            'email'       => ['sometimes', 'nullable', 'email', 'max:150'],
            'telepon'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'alamat'      => ['sometimes', 'nullable', 'string'],
            'kontak_pic'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
