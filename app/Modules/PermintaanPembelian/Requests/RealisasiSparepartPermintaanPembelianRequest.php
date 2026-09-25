<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RealisasiSparepartPermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_pembelian'    => ['required', 'date'],
            'id_supplier'          => ['nullable', 'string', 'max:36'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.id_item'      => ['required', 'string', 'max:36', 'distinct'],
            'items.*.harga_aktual' => ['required', 'numeric', 'min:0'],
        ];
    }
}
