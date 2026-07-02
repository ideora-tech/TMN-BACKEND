<?php

declare(strict_types=1);

namespace App\Modules\Modul\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreModulRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_modul' => ['required', 'string', 'max:50'],
            'nama_modul' => ['required', 'string', 'max:100'],
            'urutan'     => ['sometimes', 'integer', 'min:0'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
