<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RekapHutangVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bulan' => ['required', 'date_format:Y-m'],
        ];
    }

    public function messages(): array
    {
        return [
            'bulan.required'    => 'Bulan wajib dipilih',
            'bulan.date_format' => 'Format bulan harus YYYY-MM',
        ];
    }
}
