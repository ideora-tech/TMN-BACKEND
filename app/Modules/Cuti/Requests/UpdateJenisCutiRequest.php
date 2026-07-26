<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJenisCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_jenis'       => ['sometimes', 'required', 'string', 'max:100'],
            'mengurangi_saldo' => ['sometimes', 'boolean'],
            'aktif'            => ['sometimes', 'boolean'],
            'keterangan'       => ['sometimes', 'nullable', 'string'],
        ];
    }
}
