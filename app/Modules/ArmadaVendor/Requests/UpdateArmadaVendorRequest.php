<?php

declare(strict_types=1);

namespace App\Modules\ArmadaVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateArmadaVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor' => ['sometimes', 'string', 'max:36'],
            'nopol'     => ['sometimes', 'string', 'max:20'],
            'merk'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'jenis'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'tahun'     => ['sometimes', 'nullable', 'integer'],
            'aktif'     => ['sometimes', 'boolean'],
        ];
    }
}
