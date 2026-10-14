<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeeSupirSayaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bulan' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ];
    }

    public function messages(): array
    {
        return [
            'bulan.date_format' => 'Format bulan harus TTTT-BB, misalnya 2026-10',
        ];
    }
}
