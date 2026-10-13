<?php

declare(strict_types=1);

namespace App\Support\Impor;

use Illuminate\Foundation\Http\FormRequest;

class ImporExcelRequest extends FormRequest
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

    public function messages(): array
    {
        return [
            'file.required' => 'File Excel wajib dipilih',
            'file.mimes'    => 'File harus berformat .xlsx atau .xls',
            'file.max'      => 'Ukuran file maksimal 5 MB',
        ];
    }
}
