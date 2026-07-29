<?php

declare(strict_types=1);

namespace App\Modules\PembayaranVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePembayaranVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_bayar' => ['required', 'date_format:Y-m-d'],
            'nominal'       => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'metode'        => ['required', 'string', 'in:transfer,tunai,giro'],
            'bank_pengirim' => ['sometimes', 'nullable', 'string', 'max:100'],
            'no_referensi'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'catatan'       => ['sometimes', 'nullable', 'string'],
            'bukti'         => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
