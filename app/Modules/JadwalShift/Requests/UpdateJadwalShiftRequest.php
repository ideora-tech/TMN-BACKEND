<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJadwalShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_shift'               => ['required', 'string', 'exists:shift,id_shift,dihapus_pada,NULL'],
            'id_supir_pengganti'     => ['sometimes', 'nullable', 'string', 'exists:supir,id_supir,dihapus_pada,NULL'],
            'id_armada_override'     => ['sometimes', 'nullable', 'string', 'exists:armada,id_armada,dihapus_pada,NULL'],
            'titik_drop_override'    => ['sometimes', 'nullable', 'array'],
            'titik_drop_override.*'  => ['string', 'max:200'],
        ];
    }
}
