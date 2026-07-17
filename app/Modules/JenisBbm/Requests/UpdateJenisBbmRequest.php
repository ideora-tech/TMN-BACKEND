<?php

declare(strict_types=1);

namespace App\Modules\JenisBbm\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJenisBbmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_bbm' => ['sometimes', 'string', 'max:50'],
            'aktif'    => ['sometimes', 'boolean'],
        ];
    }
}
