<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BeliTunaiPermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_pembelian'    => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'id_supplier'          => ['nullable', 'string', 'max:36'],
            'nama_toko'            => ['nullable', 'required_without:id_supplier', 'string', 'max:150'],
            'nama_penalang'        => ['required', 'string', 'max:150'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.id_item'      => ['required', 'string', 'max:36', 'distinct'],
            'items.*.qty_dibeli'   => ['required', 'integer', 'min:0'],
            'items.*.harga_aktual' => ['required', 'numeric', 'min:0'],
            'items.*.id_barang'    => ['nullable', 'string', 'max:36'],
            'diskon'               => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'ppn_persen'           => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'ongkir'               => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'bukti'                => ['required', 'array', 'min:1', 'max:10'],
            'bukti.*'              => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_toko.required_without' => 'Pilih supplier atau isi nama toko',
            'bukti.required'             => 'Nota pembelian wajib diunggah',
            'tanggal_pembelian.before_or_equal' => 'Tanggal pembelian tidak boleh di masa depan',
        ];
    }
}
