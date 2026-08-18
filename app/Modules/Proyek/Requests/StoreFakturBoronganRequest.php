<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFakturBoronganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nominal'        => ['required', 'numeric', 'min:1'],
            'uraian'         => ['required', 'string', 'max:500'],
            'tanggal_faktur' => ['required', 'date'],
            'jatuh_tempo'    => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_faktur'],
        ];
    }
}
