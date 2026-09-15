<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }
}
