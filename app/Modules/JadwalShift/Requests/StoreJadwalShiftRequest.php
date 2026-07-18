<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJadwalShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek'      => ['required', 'string', 'exists:proyek,id_proyek,dihapus_pada,NULL'],
            'id_shift'       => ['required', 'string', 'exists:shift,id_shift,dihapus_pada,NULL'],
            'tanggal'        => ['required', 'date_format:Y-m-d'],
            'tanggal_sampai' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal'],
            'supir'          => ['required', 'array', 'min:1'],
            'supir.*'        => ['required', 'string', 'exists:supir,id_supir,dihapus_pada,NULL'],
        ];
    }
}
