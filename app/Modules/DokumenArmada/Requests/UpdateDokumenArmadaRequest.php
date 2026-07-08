<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDokumenArmadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis_dokumen'  => ['sometimes', 'required', 'string', 'max:50'],
            'nomor'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'berlaku_sampai' => ['sometimes', 'nullable', 'date'],
            'url_file'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'file'           => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
