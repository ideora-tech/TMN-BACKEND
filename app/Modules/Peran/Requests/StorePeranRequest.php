<?php

declare(strict_types=1);

namespace App\Modules\Peran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePeranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_peran'  => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nama_peran'  => ['required', 'string', 'max:100'],
            'is_platform' => ['sometimes', 'boolean'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
