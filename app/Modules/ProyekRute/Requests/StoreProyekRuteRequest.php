<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProyekRuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_rute'             => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan'  => ['sometimes', 'nullable', 'string', 'max:36'],
            'harga_penawaran'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estimasi_ritase'     => ['sometimes', 'integer', 'min:1'],
            'komponen_uang_jalan'                          => ['sometimes', 'nullable', 'array', 'max:50'],
            'komponen_uang_jalan.*.id_komponen_uang_jalan' => ['required', 'string', 'max:36'],
            'komponen_uang_jalan.*.nominal'                => ['required', 'numeric', 'min:0', 'max:100000000'],
            'keterangan'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}
