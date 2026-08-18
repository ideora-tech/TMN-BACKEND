<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_klien'        => ['required_without:id_penawaran', 'string', 'max:36'],
            'kode_proyek'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'nama_proyek'     => ['required', 'string', 'max:200'],
            'tanggal_mulai'   => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai' => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'harga_penawaran' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'harga_proyek'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status'          => ['sometimes', 'string', 'in:draft,aktif,selesai,batal'],
            'tipe_harga'      => ['sometimes', 'string', 'in:per_rit,borongan'],
            'keterangan'      => ['sometimes', 'nullable', 'string'],
            'id_penawaran'    => ['sometimes', 'nullable', 'string', 'exists:penawaran,id_penawaran,dihapus_pada,NULL'],
            'rute'                       => ['sometimes', 'array'],
            'rute.*.id_rute'             => ['required_with:rute', 'string', 'max:36'],
            'rute.*.id_jenis_kendaraan'  => ['sometimes', 'nullable', 'string', 'max:36'],
            'rute.*.harga_penawaran'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rute.*.estimasi_ritase'     => ['sometimes', 'integer', 'min:1'],
            'rute.*.uang_jalan'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rute.*.estimasi_tol'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rute.*.estimasi_bbm'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rute.*.estimasi_biaya_lain' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rute.*.keterangan'          => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_penawaran.exists' => 'Penawaran tidak ditemukan',
        ];
    }
}
