<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HapusMassalProyekUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'string', 'max:36', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Pilih minimal satu unit',
            'ids.min'      => 'Pilih minimal satu unit',
            'ids.max'      => 'Maksimal 200 unit sekali hapus',
        ];
    }
}
