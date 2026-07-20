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
            'id_rute'            => ['sometimes', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['sometimes', 'string', 'max:36'],
            'id_tarif_rute'      => ['sometimes', 'nullable', 'string', 'max:36'],
            'harga_penawaran'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'keterangan'         => ['sometimes', 'nullable', 'string'],
        ];
    }
}
