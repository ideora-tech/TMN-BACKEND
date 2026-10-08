<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AjukanUlangPembayaranPermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catatan'              => ['required', 'string', 'min:3', 'max:500'],
            'id_termin'            => ['sometimes', 'nullable', 'string', 'max:36'],
            'versi_pengajuan'      => ['sometimes', 'nullable', 'string', 'max:30'],
            'items'                => ['sometimes', 'array', 'min:1'],
            'items.*.id_item'      => ['required', 'string', 'max:36', 'distinct'],
            'items.*.harga_aktual' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'diskon'               => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'ppn_persen'           => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'ongkir'               => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
