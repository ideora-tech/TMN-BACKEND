<?php

declare(strict_types=1);

namespace App\Modules\Barang\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PemakaianBarangRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty'        => ['required', 'integer', 'min:1'],
            'tanggal'    => ['required', 'date'],
            'pemakai'    => ['required', 'string', 'max:150'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }
}
