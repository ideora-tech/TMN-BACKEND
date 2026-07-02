<?php

declare(strict_types=1);

namespace App\Modules\Faktur\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStatusFakturRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:draft,terkirim,lunas,batal'],
        ];
    }
}
