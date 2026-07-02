<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaketLanggananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_paket'    => ['required', 'string', 'max:50'],
            'nama'          => ['required', 'string', 'max:100'],
            'maks_karyawan' => ['required', 'integer', 'min:0'],
            'harga'         => ['required', 'numeric', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
