<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntervalPerawatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_perawatan' => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
            'interval_hari'      => ['required', 'integer', 'min:1'],
            'aktif'               => ['sometimes', 'boolean'],
        ];
    }
}
