<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProyekRuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_rute'             => ['sometimes', 'string', 'max:36'],
            'id_jenis_kendaraan'  => ['sometimes', 'nullable', 'string', 'max:36'],
            'harga_penawaran'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_ritase'     => ['sometimes', 'integer', 'min:1'],
            'uang_jalan'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_tol'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_bbm'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_biaya_lain' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'keterangan'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}
