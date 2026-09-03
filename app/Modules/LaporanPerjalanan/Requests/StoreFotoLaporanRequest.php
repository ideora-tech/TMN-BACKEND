<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFotoLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'foto'              => ['required', 'array', 'min:1'],
            'foto.*'            => ['file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'keterangan'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'foto_keterangan'   => ['sometimes', 'array'],
            'foto_keterangan.*' => ['nullable', 'string', 'max:200'],
        ];
    }
}
