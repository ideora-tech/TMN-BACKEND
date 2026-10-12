<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UbahNomorFakturPajakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nomor_faktur_pajak' => ['present', 'nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'nomor_faktur_pajak.present' => 'Nomor faktur pajak wajib dikirim',
            'nomor_faktur_pajak.max'     => 'Nomor faktur pajak maksimal 50 karakter',
        ];
    }
}
