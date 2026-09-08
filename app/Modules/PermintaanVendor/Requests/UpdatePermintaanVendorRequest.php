<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePermintaanVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek'          => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['sometimes', 'nullable', 'string', 'max:36'],
            'jumlah_unit'        => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'mekanisme'          => ['sometimes', 'string', 'in:unit_only,unit_driver,full'],
            'periode_dari'       => ['sometimes', 'nullable', 'date'],
            'periode_sampai'     => ['sometimes', 'nullable', 'date', 'after_or_equal:periode_dari'],
            'catatan'            => ['sometimes', 'nullable', 'string'],

            'unit'                      => ['sometimes', 'array', 'min:1', 'max:20'],
            'unit.*.id_jenis_kendaraan' => ['sometimes', 'nullable', 'string', 'max:36'],
            'unit.*.jumlah_unit'        => ['required_with:unit', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
