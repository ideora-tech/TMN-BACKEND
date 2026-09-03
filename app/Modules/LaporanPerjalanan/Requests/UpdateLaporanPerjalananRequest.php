<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLaporanPerjalananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'biaya_bbm'               => ['sometimes', 'numeric', 'min:0'],
            'id_jenis_bbm'            => ['sometimes', 'nullable', 'string', 'max:36'],
            'jumlah_liter'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'jarak_tempuh_km'         => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'uang_jalan'              => ['sometimes', 'numeric', 'min:0'],
            'uang_tol'                => ['sometimes', 'numeric', 'min:0'],
            'catatan_insiden'         => ['sometimes', 'nullable', 'string'],
            'biaya_lain'                  => ['sometimes', 'array'],
            'biaya_lain.*.nama_biaya'     => ['required_with:biaya_lain', 'string', 'max:100'],
            'biaya_lain.*.nominal'        => ['required_with:biaya_lain', 'numeric', 'min:0'],
            'biaya_tagihan'               => ['sometimes', 'array', 'max:10'],
            'biaya_tagihan.*.nama_biaya'  => ['required_with:biaya_tagihan', 'string', 'max:100'],
            'biaya_tagihan.*.nominal'     => ['required_with:biaya_tagihan', 'numeric', 'min:0'],
            'foto'                        => ['sometimes', 'array'],
            'foto.*'                      => ['file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'foto_keterangan'             => ['sometimes', 'array'],
            'foto_keterangan.*'           => ['nullable', 'string', 'max:200'],
        ];
    }
}
