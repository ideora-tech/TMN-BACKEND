<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHargaBbmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'harga_per_liter' => ['required', 'numeric', 'min:0'],
            'berlaku_mulai'   => ['required', 'date'],
        ];
    }
}
