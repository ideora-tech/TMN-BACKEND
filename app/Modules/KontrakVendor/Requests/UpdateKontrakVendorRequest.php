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
            'id_vendor'              => ['sometimes', 'string', 'max:36'],
            'id_proyek'              => ['sometimes', 'nullable', 'string', 'max:36'],
            'nomor_kontrak'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'mekanisme'              => ['sometimes', 'string', 'in:unit_only,unit_driver,full'],
            'jenis_layanan'          => ['sometimes', 'nullable', 'string', 'max:150'],
            'nilai_kontrak'          => ['sometimes', 'numeric', 'min:0'],
            'rate'                   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'satuan'                 => ['sometimes', 'nullable', 'string', 'max:50'],
            'pajak_persen'           => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'termin_pembayaran_hari' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'tanggal_mulai'          => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai'        => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'status'                 => ['sometimes', 'string', 'max:50'],
        ];
    }
}
