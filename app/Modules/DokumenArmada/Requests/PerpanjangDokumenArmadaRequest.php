<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PerpanjangDokumenArmadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nomor'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'berlaku_sampai' => ['required', 'date'],
            'file'           => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'berlaku_sampai.required' => 'Tanggal berlaku baru wajib diisi',
            'file.required'           => 'File dokumen baru wajib diunggah',
            'file.mimes'              => 'File dokumen harus PDF/JPG/PNG',
            'file.max'                => 'Ukuran file dokumen maksimal 5 MB',
        ];
    }
}
