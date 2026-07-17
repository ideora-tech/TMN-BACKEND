<?php

declare(strict_types=1);

namespace App\Modules\TarifRute\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTarifRuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_rute'             => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan'  => ['required', 'string', 'max:36'],
            'id_klien'            => ['sometimes', 'nullable', 'string', 'max:36'],
            'harga'               => ['required', 'numeric', 'min:0'],
            'estimasi_tol'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_bbm'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_uang_jalan' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_biaya_lain' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tanggal_mulai'       => ['required', 'date'],
            'tanggal_berakhir'    => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan'          => ['sometimes', 'nullable', 'string'],
            'aktif'               => ['sometimes', 'boolean'],
        ];
    }
}
