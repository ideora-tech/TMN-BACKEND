<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntervalPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_kendaraan'        => ['sometimes', 'string', 'max:36'],
            'interval_km'               => ['sometimes', 'nullable', 'integer', 'min:1'],
            'interval_bulan'            => ['sometimes', 'nullable', 'integer', 'min:1'],
            'aktif'                     => ['sometimes', 'boolean'],
            'sparepart'                 => ['sometimes', 'array', 'max:30'],
            'sparepart.*.id_sparepart'  => ['required', 'string', 'max:36'],
            'sparepart.*.qty_standar'   => ['required', 'integer', 'min:1'],
        ];
    }
}
