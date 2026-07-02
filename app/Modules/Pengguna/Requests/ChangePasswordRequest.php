<?php

declare(strict_types=1);

namespace App\Modules\Pengguna\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_lama' => ['required', 'string'],
            'password_baru'  => ['required', 'string', 'min:8'],
        ];
    }
}
