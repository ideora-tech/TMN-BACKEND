<?php

declare(strict_types=1);

namespace App\Modules\Perusahaan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePerusahaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'         => ['required', 'string', 'max:200'],
            'email'        => ['nullable', 'email', 'max:150'],
            'telepon'      => ['nullable', 'string', 'max:30'],
            'alamat'       => ['nullable', 'string', 'max:500'],
            'id_zona'      => ['nullable', 'string', 'size:36'],
            'id_mata_uang' => ['nullable', 'string', 'size:36'],
        ];
    }
}
