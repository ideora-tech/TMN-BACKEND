<?php

declare(strict_types=1);

namespace App\Modules\Shift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'         => ['required', 'string', 'max:100'],
            'jam_mulai'    => ['required', 'date_format:H:i'],
            'jam_selesai'  => ['required', 'date_format:H:i'],
            'aktif'        => ['sometimes', 'boolean'],
        ];
    }
}
