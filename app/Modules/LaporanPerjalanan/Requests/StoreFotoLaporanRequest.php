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
            'file'       => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
