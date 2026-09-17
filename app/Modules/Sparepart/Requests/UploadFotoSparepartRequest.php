<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadFotoSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'foto'   => ['required', 'array', 'min:1', 'max:10'],
            'foto.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'foto.required' => 'Minimal satu foto wajib diunggah',
            'foto.max'      => 'Maksimal 10 foto per unggahan',
            'foto.*.mimes'  => 'Foto harus berupa jpg, jpeg, png, atau webp',
            'foto.*.max'    => 'Ukuran foto maksimal 5 MB',
        ];
    }
}
