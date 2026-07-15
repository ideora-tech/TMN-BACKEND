<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupirVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor' => ['required', 'string', 'max:36'],
            'nama'      => ['required', 'string', 'max:150'],
            'telepon'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'no_sim'    => ['sometimes', 'nullable', 'string', 'max:50'],
            'aktif'     => ['sometimes', 'boolean'],
        ];
    }
}
