<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KirimEmailPenawaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_tujuan' => ['required', 'email', 'max:150'],
            'subjek'       => ['required', 'string', 'max:200'],
            'pesan'        => ['required', 'string', 'max:5000'],
            'lampiran'     => ['sometimes', 'array', 'max:10'],
            'lampiran.*'   => ['file', 'mimes:jpg,jpeg,png,webp,pdf,xls,xlsx,doc,docx', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'email_tujuan.required' => 'Alamat email tujuan wajib diisi',
            'email_tujuan.email'    => 'Format email tujuan tidak valid',
            'subjek.required'       => 'Subjek wajib diisi',
            'pesan.required'        => 'Pesan wajib diisi',
            'lampiran.max'          => 'Maksimal 10 file lampiran tambahan',
            'lampiran.*.mimes'      => 'Lampiran harus berupa gambar, PDF, atau dokumen Office (jpg, png, pdf, xls, xlsx, doc, docx)',
            'lampiran.*.max'        => 'Ukuran tiap lampiran maksimal 5 MB',
        ];
    }
}
