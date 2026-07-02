<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRekonsiliasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catatan_klien'    => ['sometimes', 'nullable', 'string'],
            'catatan_keuangan' => ['sometimes', 'nullable', 'string'],
            'status'           => ['sometimes', 'string', 'in:pending,selesai'],
        ];
    }
}
