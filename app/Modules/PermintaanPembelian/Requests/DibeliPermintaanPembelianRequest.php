<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DibeliPermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_supplier'           => ['required', 'string', 'max:36'],
            'tanggal_pembelian'     => ['required', 'date'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.id_item'       => ['required', 'string', 'max:36', 'distinct'],
            'items.*.harga_aktual'  => ['required', 'numeric', 'min:0'],
            'items.*.id_barang'     => ['nullable', 'string', 'max:36'],
            'termin'                => ['nullable', 'array'],
            'termin.*.nama'         => ['required', 'string', 'max:100'],
            'termin.*.nominal'      => ['required', 'numeric', 'min:0.01'],
            'termin.*.jatuh_tempo'  => ['nullable', 'date'],
        ];
    }
}
