<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIzinPeranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'diizinkan' => ['required', 'boolean'],
        ];
    }
}
