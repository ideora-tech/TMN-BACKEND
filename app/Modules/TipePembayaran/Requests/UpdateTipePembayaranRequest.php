<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTipePembayaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_tipe' => ['sometimes', 'string', 'max:50'],
            'nama_tipe' => ['sometimes', 'string', 'max:150'],
            'aktif'     => ['sometimes', 'boolean'],
        ];
    }
}
