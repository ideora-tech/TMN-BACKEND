<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use App\Modules\PermintaanPembelian\PermintaanPembelianService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PesanPermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_supplier'          => ['required', 'string', 'max:36'],
            'tanggal_po'           => ['required', 'date'],
            'syarat_pembayaran'    => ['nullable', 'string', Rule::in(PermintaanPembelianService::SYARAT_PEMBAYARAN)],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.id_item'      => ['required', 'string', 'max:36', 'distinct'],
            'items.*.harga_aktual' => ['required', 'numeric', 'min:0'],
            'items.*.id_barang'    => ['nullable', 'string', 'max:36'],
            'diskon'               => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'ppn_persen'           => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'ongkir'               => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
