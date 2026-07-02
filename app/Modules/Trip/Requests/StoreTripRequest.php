<?php

declare(strict_types=1);

namespace App\Modules\Trip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jadwal' => ['required', 'string', 'max:36'],
            'catatan'   => ['sometimes', 'nullable', 'string'],
        ];
    }
}
