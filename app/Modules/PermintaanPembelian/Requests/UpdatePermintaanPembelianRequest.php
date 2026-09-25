<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian\Requests;

use App\Modules\PermintaanPembelian\PermintaanPembelianService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermintaanPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'judul'                  => ['required', 'string', 'max:150'],
            'tipe'                   => ['required', 'string', Rule::in(PermintaanPembelianService::TIPE)],
            'alasan'                 => ['required', 'string', 'max:2000'],
            'id_departemen'          => ['nullable', 'string', 'max:36'],
            'id_perawatan'           => ['nullable', 'string', 'max:36'],
            'tanggal_permintaan'     => ['required', 'date'],
            'tanggal_dibutuhkan'     => ['nullable', 'date', 'after_or_equal:tanggal_permintaan'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.jenis'          => ['required', 'string', Rule::in(PermintaanPembelianService::JENIS_ITEM)],
            'items.*.id_barang'      => ['nullable', 'string', 'max:36'],
            'items.*.id_sparepart'   => ['nullable', 'string', 'max:36'],
            'items.*.id_jenis_kendaraan' => ['nullable', 'string', 'max:36'],
            'items.*.merk'           => ['nullable', 'string', 'max:100'],
            'items.*.model'          => ['nullable', 'string', 'max:100'],
            'items.*.tahun'          => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'items.*.nama_item'      => ['nullable', 'required_unless:items.*.jenis,sparepart,aset', 'string', 'max:150'],
            'items.*.spesifikasi'    => ['nullable', 'string', 'max:1000'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'items.*.satuan'         => ['nullable', 'required_unless:items.*.jenis,sparepart,aset', 'string', 'max:30'],
            'items.*.harga_estimasi' => ['required', 'numeric', 'min:0'],
            'items.*.keterangan'     => ['nullable', 'string', 'max:255'],
        ];
    }
}
