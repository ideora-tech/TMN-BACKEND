<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePenawaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nomor_penawaran'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'judul'            => ['required', 'string', 'max:200'],
            'id_klien'         => ['required', 'string', 'max:36'],
            'nilai_penawaran'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status'           => ['sometimes', 'in:draft,terkirim,negosiasi,disetujui,ditolak'],
            'tipe_harga'       => ['sometimes', 'string', 'in:per_rit,borongan'],
            'tanggal_penawaran'=> ['sometimes', 'nullable', 'date'],
            'tanggal_berlaku'  => ['sometimes', 'nullable', 'date'],
            'catatan'          => ['sometimes', 'nullable', 'string'],

            'items'                      => ['sometimes', 'array'],
            'items.*.id_rute'            => ['required_with:items', 'string', 'max:36'],
            'items.*.id_jenis_kendaraan' => ['required_with:items', 'string', 'max:36'],
            'items.*.harga_satuan'       => ['required_unless:tipe_harga,borongan', 'nullable', 'numeric', 'min:0'],
            'items.*.estimasi_ritase'    => ['sometimes', 'integer', 'min:1'],
            'items.*.keterangan'         => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_klien.required' => 'Klien wajib dipilih',
        ];
    }
}
