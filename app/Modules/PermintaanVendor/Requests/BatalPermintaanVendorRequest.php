<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatalPermintaanVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'max:500'],
        ];
    }
}
