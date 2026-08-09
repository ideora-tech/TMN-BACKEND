<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTokenPerangkatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'in:android,ios'],
        ];
    }
}
