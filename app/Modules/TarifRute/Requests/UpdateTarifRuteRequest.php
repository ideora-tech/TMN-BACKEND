<?php

declare(strict_types=1);

namespace App\Modules\TarifRute\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTarifRuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_rute'             => ['sometimes', 'string', 'max:36'],
            'id_jenis_kendaraan'  => ['sometimes', 'string', 'max:36'],
            'id_klien'            => ['sometimes', 'nullable', 'string', 'max:36'],
            'harga'               => ['sometimes', 'numeric', 'min:0'],
            'estimasi_tol'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_bbm'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_uang_jalan' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_biaya_lain' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tanggal_mulai'       => ['sometimes', 'date'],
            'tanggal_berakhir'    => ['sometimes', 'nullable', 'date'],
            'keterangan'          => ['sometimes', 'nullable', 'string'],
            'aktif'               => ['sometimes', 'boolean'],
        ];
    }
}
