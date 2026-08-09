<?php

declare(strict_types=1);

namespace App\Modules\WajahReferensi\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DaftarWajahRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'foto'        => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'embedding'   => ['required', 'json'],
            'model_versi' => ['required', 'string', 'max:50'],
        ];
    }
}
