<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKontrakVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor'              => ['required', 'string', 'max:36'],
            'id_proyek'              => ['sometimes', 'nullable', 'string', 'max:36'],
            'nomor_kontrak'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'mekanisme'              => ['required', 'string', 'in:unit_only,unit_driver,full'],
            'jenis_layanan'          => ['sometimes', 'nullable', 'string', 'max:150'],
            'nilai_kontrak'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rate'                   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'satuan'                 => ['sometimes', 'nullable', 'string', 'in:per trip,per ton,per hari,per bulan,lumpsum'],
            'pajak_persen'           => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'termin_pembayaran_hari' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'tanggal_mulai'          => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai'        => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'status'                 => ['sometimes', 'string', 'max:50'],
            'salin_dari_kontrak'     => ['sometimes', 'nullable', 'string', 'max:36'],
            'unit'                   => ['sometimes', 'array'],
            'unit.*.nopol'              => ['required', 'string', 'max:20'],
            'unit.*.merk'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'unit.*.jenis'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'unit.*.id_jenis_kendaraan' => ['sometimes', 'nullable', 'string', 'max:36'],
            'unit.*.kapasitas'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit.*.tahun'              => ['sometimes', 'nullable', 'integer'],
            'unit.*.masa_berlaku_stnk'  => ['required', 'date'],
            'unit.*.masa_berlaku_kir'   => ['required', 'date'],
            'unit.*.supir_index'        => ['sometimes', 'nullable', 'integer', 'min:0'],
            'supir'                  => ['sometimes', 'array'],
            'supir.*.nama'             => ['required', 'string', 'max:150'],
            'supir.*.telepon'          => ['sometimes', 'nullable', 'string', 'max:30'],
            'supir.*.no_sim'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'supir.*.masa_berlaku_sim' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
