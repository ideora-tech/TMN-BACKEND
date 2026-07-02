<?php

declare(strict_types=1);

namespace App\Modules\BriefingSupir\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBriefingSupirRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catatan_rute'        => ['sometimes', 'nullable', 'string'],
            'catatan_keselamatan' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
