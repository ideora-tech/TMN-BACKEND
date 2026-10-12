<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadLampiranMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lampiran'   => ['required', 'array', 'min:1', 'max:10'],
            'lampiran.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf,xls,xlsx,doc,docx', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'lampiran.required' => 'Minimal satu berkas wajib diunggah',
            'lampiran.array'    => 'Minimal satu berkas wajib diunggah',
            'lampiran.max'      => 'Maksimal 10 berkas per unggahan',
            'lampiran.*.file'   => 'Berkas tidak valid',
            'lampiran.*.mimes'  => 'Berkas harus berupa foto (jpg, png, webp), PDF, Word, atau Excel',
            'lampiran.*.max'    => 'Ukuran berkas maksimal 5 MB',
        ];
    }
}
