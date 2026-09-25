<?php

declare(strict_types=1);

namespace App\Modules\Barang\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PenyesuaianBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stok_baru'  => ['required', 'integer', 'min:0'],
            'keterangan' => ['required', 'string', 'max:255'],
        ];
    }
}
