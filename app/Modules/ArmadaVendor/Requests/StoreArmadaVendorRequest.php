<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArmadaVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor'         => ['required', 'string', 'max:36'],
            'id_kontrak_vendor' => ['sometimes', 'nullable', 'string', 'max:36'],
            'nopol'             => ['required', 'string', 'max:20'],
            'merk'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'jenis'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'id_jenis_kendaraan' => ['sometimes', 'nullable', 'string', 'max:36'],
            'kapasitas'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'tahun'             => ['sometimes', 'nullable', 'integer'],
            'masa_berlaku_stnk' => ['required', 'date'],
            'masa_berlaku_kir' => ['required', 'date'],
            'id_supir_vendor_default' => ['sometimes', 'nullable', 'string', 'max:36'],
            'aktif'             => ['sometimes', 'boolean'],
        ];
    }
}
