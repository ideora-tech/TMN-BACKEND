<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeeSupirUrutanTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek'    => ['required', 'string', 'max:36'],
            'id_supir'     => ['required', 'string', 'max:36'],
            'tanggal'      => ['required', 'date_format:Y-m-d'],
            'id_penugasan' => ['nullable', 'string', 'max:36'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.date_format' => 'Format tanggal harus YYYY-MM-DD',
        ];
    }
}
