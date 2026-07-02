<?php

declare(strict_types=1);

namespace App\Modules\Peran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePeranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_peran'  => ['sometimes', 'string', 'max:50'],
            'nama_peran'  => ['sometimes', 'string', 'max:100'],
            'is_platform' => ['sometimes', 'boolean'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
