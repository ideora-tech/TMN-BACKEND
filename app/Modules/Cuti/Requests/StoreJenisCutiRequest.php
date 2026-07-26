<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJenisCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_jenis'       => ['required', 'string', 'max:100'],
            'mengurangi_saldo' => ['sometimes', 'boolean'],
            'aktif'            => ['sometimes', 'boolean'],
            'keterangan'       => ['sometimes', 'nullable', 'string'],
        ];
    }
}
