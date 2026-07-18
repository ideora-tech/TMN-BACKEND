<?php

declare(strict_types=1);

namespace App\Modules\Shift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'         => ['sometimes', 'string', 'max:100'],
            'jam_mulai'    => ['sometimes', 'date_format:H:i'],
            'jam_selesai'  => ['sometimes', 'date_format:H:i'],
            'aktif'        => ['sometimes', 'boolean'],
        ];
    }
}
