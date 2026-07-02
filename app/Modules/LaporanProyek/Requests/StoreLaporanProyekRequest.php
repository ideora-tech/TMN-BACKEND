<?php

declare(strict_types=1);

namespace App\Modules\LaporanProyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLaporanProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek' => ['required', 'string', 'max:36'],
            'ringkasan' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
