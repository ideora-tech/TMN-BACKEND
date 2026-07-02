<?php

declare(strict_types=1);

namespace App\Modules\Departemen\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartemenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_departemen_induk' => ['sometimes', 'nullable', 'string', 'uuid'],
            'kode_departemen'     => ['sometimes', 'string', 'max:50'],
            'nama_departemen'     => ['sometimes', 'string', 'max:150'],
            'aktif'               => ['sometimes', 'boolean'],
        ];
    }
}
