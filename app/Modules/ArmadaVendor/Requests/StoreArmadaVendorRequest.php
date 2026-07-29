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
            'nopol'             => ['required', 'string', 'max:20'],
            'merk'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'jenis'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'kapasitas'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'tahun'             => ['sometimes', 'nullable', 'integer'],
            'masa_berlaku_stnk' => ['sometimes', 'nullable', 'date'],
            'masa_berlaku_kir'  => ['sometimes', 'nullable', 'date'],
            'aktif'             => ['sometimes', 'boolean'],
        ];
    }
}
