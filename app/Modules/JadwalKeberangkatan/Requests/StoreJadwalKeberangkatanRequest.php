<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJadwalKeberangkatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_penugasan'    => ['required', 'string', 'max:36'],
            'waktu_berangkat' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
            'rute'            => ['sometimes', 'nullable', 'string'],
            'estimasi_tiba'   => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }
}
