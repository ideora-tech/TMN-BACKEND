<?php

declare(strict_types=1);

namespace App\Modules\PaketLangganan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaketLanggananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_paket'    => ['sometimes', 'string', 'max:50'],
            'nama'          => ['sometimes', 'string', 'max:100'],
            'maks_karyawan' => ['sometimes', 'integer', 'min:0'],
            'harga'         => ['sometimes', 'numeric', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
