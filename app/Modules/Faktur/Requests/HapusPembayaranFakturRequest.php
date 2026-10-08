<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HapusPembayaranFakturRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:3', 'max:150'],
        ];
    }
}
