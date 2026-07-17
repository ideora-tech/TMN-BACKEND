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
            'id_jenis_perawatan'       => ['sometimes', 'nullable', 'string', 'exists:jenis_perawatan,id_jenis_perawatan,dihapus_pada,NULL'],
            'jenis_perawatan'          => ['required_without:id_jenis_perawatan', 'string', 'max:150'],
            'biaya'                    => ['sometimes', 'numeric', 'min:0'],
            'km_odometer'              => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'                   => ['sometimes', 'in:terjadwal,dalam_proses,selesai'],
            'jadwal_servis_berikutnya' => ['sometimes', 'nullable', 'date'],
            'keterangan'               => ['sometimes', 'nullable', 'string'],
            'sparepart'                => ['sometimes', 'array'],
            'sparepart.*.id_sparepart' => ['required', 'string', 'exists:sparepart,id_sparepart,dihapus_pada,NULL'],
            'sparepart.*.qty'          => ['required', 'integer', 'min:1'],
            'sparepart.*.harga'        => ['required', 'numeric', 'min:0'],
        ];
    }
}
