<?php

declare(strict_types=1);

namespace App\Modules\EvaluasiTrip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluasiTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nilai_armada' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'nilai_supir'  => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'catatan'      => ['sometimes', 'nullable', 'string'],
        ];
    }
}
