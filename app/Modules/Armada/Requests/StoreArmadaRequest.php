<?php

declare(strict_types=1);

namespace App\Modules\Armada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreArmadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_kendaraan' => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_vendor'          => ['sometimes', 'nullable', 'string', 'max:36'],
            'nopol'              => ['required', 'string', 'max:20'],
            'merk'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'model'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'tahun'              => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'kepemilikan'        => ['sometimes', 'in:internal,vendor'],
            'status'             => ['sometimes', 'string', 'max:50'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
