<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPenugasanHarianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'          => ['required', 'date'],
            'tanggal_sampai'   => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal'],
            'id_armada'        => ['required_without:id_armada_vendor', 'prohibits:id_armada_vendor', 'nullable', 'string', 'max:36'],
            'id_armada_vendor' => ['required_without:id_armada', 'prohibits:id_armada', 'nullable', 'string', 'max:36'],
            'id_supir'         => ['required_without:id_supir_vendor', 'prohibits:id_supir_vendor', 'nullable', 'string', 'max:36'],
            'id_supir_vendor'  => ['required_without:id_supir', 'prohibits:id_supir', 'nullable', 'string', 'max:36'],
            'id_proyek'        => ['required', 'string', 'max:36'],
            'id_rute'          => ['required', 'string', 'max:36'],
            'uang_jalan'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'titik_drop'       => ['sometimes', 'array', 'max:10'],
            'titik_drop.*'     => ['string', 'max:200'],
        ];
    }
}
