<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePenawaranRevisiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'judul'                      => ['sometimes', 'nullable', 'string', 'max:200'],
            'nilai_penawaran'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'catatan'                    => ['sometimes', 'nullable', 'string'],
            'items'                      => ['sometimes', 'array'],
            'items.*.id_rute'            => ['required_with:items', 'string', 'max:36'],
            'items.*.id_jenis_kendaraan' => ['sometimes', 'nullable', 'string', 'max:36'],
            'items.*.harga_satuan'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.estimasi_ritase'    => ['sometimes', 'integer', 'min:1'],
            'items.*.keterangan'         => ['sometimes', 'nullable', 'string'],
        ];
    }
}
