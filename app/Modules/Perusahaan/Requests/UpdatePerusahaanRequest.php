<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePerusahaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'         => ['sometimes', 'string', 'max:200'],
            'email'        => ['sometimes', 'nullable', 'email', 'max:150'],
            'telepon'      => ['sometimes', 'nullable', 'string', 'max:30'],
            'alamat'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'id_zona'      => ['sometimes', 'nullable', 'string', 'size:36'],
            'id_mata_uang' => ['sometimes', 'nullable', 'string', 'size:36'],
            'aktif'        => ['sometimes', 'boolean'],
        ];
    }
}
