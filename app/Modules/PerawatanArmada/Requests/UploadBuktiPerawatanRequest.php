<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadBuktiPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bukti'   => ['required', 'array', 'min:1', 'max:10'],
            'bukti.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'bukti.required' => 'Minimal satu file bukti wajib diunggah',
            'bukti.max'      => 'Maksimal 10 file per unggahan',
            'bukti.*.mimes'  => 'File bukti harus berupa foto (jpg, jpeg, png, atau webp)',
            'bukti.*.max'    => 'Ukuran file maksimal 5 MB',
        ];
    }
}
