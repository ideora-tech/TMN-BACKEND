<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit\Requests;

use App\Modules\ProyekUnit\ProyekUnitService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProyekUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit'                    => ['required', 'array', 'min:1', 'max:50'],
            'unit.*.sumber'           => ['required', Rule::in(ProyekUnitService::SUMBER_VALID)],
            'unit.*.id_armada'        => ['required_if:unit.*.sumber,internal', 'nullable', 'string', 'max:36'],
            'unit.*.id_armada_vendor' => ['required_if:unit.*.sumber,vendor', 'nullable', 'string', 'max:36'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit.required'                       => 'Pilih minimal satu unit',
            'unit.max'                            => 'Maksimal 50 unit per penambahan',
            'unit.*.sumber.in'                    => 'Sumber unit tidak dikenal',
            'unit.*.id_armada.required_if'        => 'Armada wajib dipilih',
            'unit.*.id_armada_vendor.required_if' => 'Armada vendor wajib dipilih',
        ];
    }
}
