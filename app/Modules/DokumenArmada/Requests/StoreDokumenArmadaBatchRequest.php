<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDokumenArmadaBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dokumen'                  => ['required', 'array', 'min:1', 'max:20'],
            'dokumen.*.jenis_dokumen'  => ['required', 'string', 'max:50'],
            'dokumen.*.nomor'          => ['nullable', 'string', 'max:100'],
            'dokumen.*.berlaku_sampai' => ['nullable', 'date'],
            'dokumen.*.file'           => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'dokumen.required'                => 'Isi minimal satu dokumen',
            'dokumen.*.jenis_dokumen.required' => 'Jenis dokumen wajib dipilih',
            'dokumen.*.file.required'          => 'File dokumen wajib diunggah',
            'dokumen.*.file.mimes'             => 'File dokumen harus PDF/JPG/PNG',
            'dokumen.*.file.max'               => 'Ukuran file dokumen maksimal 5 MB',
        ];
    }
}
