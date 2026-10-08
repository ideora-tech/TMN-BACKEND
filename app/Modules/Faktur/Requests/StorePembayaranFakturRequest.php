<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePembayaranFakturRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_bayar'       => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2000-01-01'],
            'nominal'             => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'potongan'            => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'keterangan_potongan' => ['sometimes', 'nullable', 'string', 'max:150'],
            'no_referensi'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'catatan'             => ['sometimes', 'nullable', 'string', 'max:500'],
            'bukti'               => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal_bayar.before_or_equal' => 'Tanggal pembayaran tidak boleh di masa depan',
        ];
    }
}
