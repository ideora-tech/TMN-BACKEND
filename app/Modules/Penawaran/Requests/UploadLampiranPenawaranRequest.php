<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadLampiranPenawaranRequest extends FormRequest
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
            'lampiran.required' => 'Minimal satu file wajib diunggah',
            'lampiran.max'      => 'Maksimal 10 file per unggahan',
            'lampiran.*.mimes'  => 'Lampiran harus berupa gambar, PDF, atau dokumen Office (jpg, png, pdf, xls, xlsx, doc, docx)',
            'lampiran.*.max'    => 'Ukuran tiap lampiran maksimal 5 MB',
        ];
    }
}
