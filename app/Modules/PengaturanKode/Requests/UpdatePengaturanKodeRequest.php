<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePengaturanKodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prefix'        => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'panjang_digit' => ['required', 'integer', 'between:3,8'],
            'reset'         => ['required', 'string', 'in:tidak,bulanan,tahunan'],
        ];
    }
}
