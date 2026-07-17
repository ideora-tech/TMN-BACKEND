<?php

declare(strict_types=1);

namespace App\Modules\JenisPerawatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJenisPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['required', 'string', 'max:150'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
