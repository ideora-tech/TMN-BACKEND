<?php

declare(strict_types=1);

namespace App\Modules\PerusahaanGrup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePerusahaanGrupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'singkatan'    => ['sometimes', 'string', 'max:30'],
            'nama_lengkap' => ['sometimes', 'nullable', 'string', 'max:150'],
            'aktif'        => ['sometimes', 'boolean'],
        ];
    }
}
