<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TutupSisaPermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:3', 'max:500'],
            'jumlah_diterima' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'diskon' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'ongkir' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
