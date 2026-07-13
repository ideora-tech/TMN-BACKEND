<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePerawatanArmadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'                  => ['sometimes', 'date'],
            'jenis_perawatan'          => ['sometimes', 'string', 'max:150'],
            'biaya'                    => ['sometimes', 'numeric', 'min:0'],
            'km_odometer'              => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'                   => ['sometimes', 'in:terjadwal,dalam_proses,selesai'],
            'jadwal_servis_berikutnya' => ['sometimes', 'nullable', 'date'],
            'keterangan'               => ['sometimes', 'nullable', 'string'],
        ];
    }
}
