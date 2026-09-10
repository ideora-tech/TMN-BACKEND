<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UbahPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_lama' => ['required', 'string'],
            'password_baru' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password_lama.required'  => 'Password lama wajib diisi',
            'password_baru.required'  => 'Password baru wajib diisi',
            'password_baru.min'       => 'Password baru minimal 8 karakter',
            'password_baru.confirmed' => 'Konfirmasi password baru tidak sama',
        ];
    }
}
