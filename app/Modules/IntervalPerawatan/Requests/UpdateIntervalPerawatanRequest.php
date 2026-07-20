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
            'id_jenis_perawatan' => ['sometimes', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['sometimes', 'string', 'max:36'],
            'interval_hari'      => ['sometimes', 'integer', 'min:1'],
            'aktif'               => ['sometimes', 'boolean'],
        ];
    }
}
