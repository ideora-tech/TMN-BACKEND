<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJenisPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['sometimes', 'string', 'max:150'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
