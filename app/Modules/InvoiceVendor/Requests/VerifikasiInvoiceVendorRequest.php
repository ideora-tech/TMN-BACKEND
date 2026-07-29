<?php

declare(strict_types=1);

namespace App\Modules\InvoiceVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifikasiInvoiceVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'aksi'    => ['required', 'string', 'in:verifikasi,tolak'],
            'catatan' => ['required_if:aksi,tolak', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'catatan.required_if' => 'Catatan wajib diisi saat menolak invoice',
        ];
    }
}
