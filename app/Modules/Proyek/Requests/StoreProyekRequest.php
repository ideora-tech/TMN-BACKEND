<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_klien'        => ['required', 'string', 'max:36'],
            'kode_proyek'     => ['required', 'string', 'max:50', Rule::unique('proyek', 'kode_proyek')],
            'nama_proyek'     => ['required', 'string', 'max:200'],
            'tanggal_mulai'   => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai' => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'status'          => ['sometimes', 'string', 'in:draft,aktif,selesai,batal'],
            'keterangan'      => ['sometimes', 'nullable', 'string'],
            'id_penawaran'    => ['sometimes', 'nullable', 'string', 'exists:penawaran,id_penawaran,dihapus_pada,NULL'],
            'rute'                       => ['sometimes', 'array'],
            'rute.*.id_rute'             => ['required_with:rute', 'string', 'max:36'],
            'rute.*.id_jenis_kendaraan'  => ['required_with:rute', 'string', 'max:36'],
            'rute.*.id_tarif_rute'       => ['sometimes', 'nullable', 'string', 'max:36'],
            'rute.*.harga_penawaran'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rute.*.keterangan'          => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode_proyek.unique'  => 'Kode proyek sudah digunakan',
            'id_penawaran.exists' => 'Penawaran tidak ditemukan',
        ];
    }
}
