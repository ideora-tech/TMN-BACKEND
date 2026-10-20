<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePerusahaanGrupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'singkatan'    => ['required', 'string', 'max:30'],
            'nama_lengkap' => ['sometimes', 'nullable', 'string', 'max:150'],
            'aktif'        => ['sometimes', 'boolean'],
        ];
    }
}
