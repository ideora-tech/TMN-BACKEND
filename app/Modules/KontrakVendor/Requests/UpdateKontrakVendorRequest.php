<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKontrakVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor'       => ['sometimes', 'string', 'max:36'],
            'id_proyek'       => ['sometimes', 'nullable', 'string', 'max:36'],
            'mekanisme'       => ['sometimes', 'string', 'in:unit_only,unit_driver,full'],
            'nilai_kontrak'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tanggal_mulai'   => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai' => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'status'          => ['sometimes', 'string', 'max:50'],
        ];
    }
}
