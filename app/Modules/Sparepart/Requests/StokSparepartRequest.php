<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StokSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis'      => ['required', 'in:penyesuaian'],
            'qty'        => ['required', 'integer', 'not_in:0'],
            'harga'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'keterangan' => ['required', 'string'],
        ];
    }
}
