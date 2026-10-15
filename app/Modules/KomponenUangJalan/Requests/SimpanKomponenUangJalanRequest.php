<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanKomponenUangJalanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $wajib = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'nama'   => [$wajib, 'string', 'max:100', 'regex:/\S/'],
            'urutan' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'aktif'  => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.required' => 'Nama komponen wajib diisi',
            'nama.regex'    => 'Nama komponen wajib diisi',
            'nama.max'      => 'Nama komponen maksimal 100 karakter',
        ];
    }
}
