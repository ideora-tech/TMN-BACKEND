<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFakturRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek'      => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_klien'       => ['sometimes', 'nullable', 'string', 'max:36'],
            'nomor_faktur'   => ['sometimes', 'string', 'max:100'],
            'status'         => ['sometimes', 'string', 'in:draft,terkirim,lunas,batal'],
            'tanggal_faktur' => ['sometimes', 'nullable', 'date'],
            'jatuh_tempo'    => ['sometimes', 'nullable', 'date'],
            'items'          => ['sometimes', 'array'],
            'items.*.deskripsi'    => ['required_with:items', 'string', 'max:300'],
            'items.*.qty'          => ['required_with:items', 'numeric', 'min:0'],
            'items.*.harga_satuan' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
