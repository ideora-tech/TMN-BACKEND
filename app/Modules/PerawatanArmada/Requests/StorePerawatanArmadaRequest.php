<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePerawatanArmadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'                  => ['required', 'date'],
            'id_interval_perawatan'    => ['sometimes', 'nullable', 'string', 'max:36'],
            'biaya'                    => ['sometimes', 'numeric', 'min:0'],
            'km_odometer'              => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'                   => ['sometimes', 'in:terjadwal,dalam_proses,selesai'],
            'jadwal_servis_berikutnya' => ['sometimes', 'nullable', 'date'],
            'keterangan'               => ['sometimes', 'nullable', 'string'],
            'id_supplier'              => ['sometimes', 'nullable', 'string', 'max:36'],
            'sparepart'                    => ['sometimes', 'array'],
            'sparepart.*.sumber'           => ['sometimes', 'in:bengkel,stok_sendiri'],
            'sparepart.*.id_sparepart'     => ['nullable', 'string', 'required_if:sparepart.*.sumber,stok_sendiri', 'exists:sparepart,id_sparepart,dihapus_pada,NULL'],
            'sparepart.*.nama_sparepart'   => ['nullable', 'string', 'max:150', 'required_without:sparepart.*.id_sparepart'],
            'sparepart.*.qty'              => ['required', 'integer', 'min:1'],
            'sparepart.*.harga'            => ['required', 'numeric', 'min:0'],
        ];
    }
}
