<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRekonsiliasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_faktur'        => ['required', 'string', 'max:36'],
            'catatan_klien'    => ['sometimes', 'nullable', 'string'],
            'catatan_keuangan' => ['sometimes', 'nullable', 'string'],
            'status'           => ['sometimes', 'string', 'in:pending,selesai'],
        ];
    }
}
