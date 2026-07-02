<?php

declare(strict_types=1);

namespace App\Modules\StatusTrip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStatusTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', 'max:100'],
            'keterangan'  => ['sometimes', 'nullable', 'string'],
            'latitude'    => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude'   => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
