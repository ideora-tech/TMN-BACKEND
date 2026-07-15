<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePenugasanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek'      => ['sometimes', 'string', 'max:36'],
            'id_armada'      => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_supir'       => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_karyawan'    => ['sometimes', 'nullable', 'string', 'max:36'],
            'tanggal_tugas'  => ['sometimes', 'nullable', 'date'],
            'status'         => ['sometimes', 'string', 'in:pending,aktif,selesai,batal'],
            'estimasi_biaya' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sumber'            => ['sometimes', 'nullable', 'string', 'in:internal,vendor'],
            'id_kontrak_vendor' => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_armada_vendor'  => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_supir_vendor'   => ['sometimes', 'nullable', 'string', 'max:36'],
        ];
    }
}
