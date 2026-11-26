<?php

declare(strict_types=1);

namespace App\Modules\KategoriPengajuan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKategoriPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'     => ['sometimes', 'required', 'string', 'max:100'],
            'aktif'    => ['sometimes', 'boolean'],
        ];
    }
}
