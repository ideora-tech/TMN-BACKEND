<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJadwalKeberangkatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_penugasan'    => ['sometimes', 'string', 'max:36'],
            'waktu_berangkat' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
            'id_rute'         => ['sometimes', 'nullable', 'string', 'exists:rute,id_rute'],
            'rute'            => ['sometimes', 'nullable', 'string'],
            'estimasi_tiba'   => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }
}
