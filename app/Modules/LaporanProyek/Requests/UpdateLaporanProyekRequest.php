<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLaporanProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ringkasan' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
