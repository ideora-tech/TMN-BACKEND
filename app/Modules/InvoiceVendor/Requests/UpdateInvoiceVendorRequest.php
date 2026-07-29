<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor'         => ['sometimes', 'required', 'string', 'max:36'],
            'id_kontrak_vendor' => ['sometimes', 'nullable', 'string', 'max:36'],
            'nomor_invoice'     => ['sometimes', 'required', 'string', 'max:100'],
            'tanggal_invoice'   => ['sometimes', 'required', 'date_format:Y-m-d'],
            'jatuh_tempo'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'no_po'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'no_do'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'dpp'               => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0'],
            'ppn'               => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'pph'               => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'keterangan'        => ['sometimes', 'nullable', 'string'],
        ];
    }
}
