<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreParameterBokRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_kendaraan'     => ['required', 'string', 'max:36'],
            'id_jenis_bbm'           => ['required', 'string', 'max:36'],
            'konsumsi_km_per_liter'  => ['required', 'numeric', 'min:0.1'],
            'biaya_ban_per_km'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'biaya_servis_per_km'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'biaya_tetap_bulanan'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'utilisasi_km_per_bulan' => ['required', 'numeric', 'min:1'],
            'margin_persen'          => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'keterangan'             => ['sometimes', 'nullable', 'string'],
            'aktif'                  => ['sometimes', 'boolean'],
        ];
    }
}
