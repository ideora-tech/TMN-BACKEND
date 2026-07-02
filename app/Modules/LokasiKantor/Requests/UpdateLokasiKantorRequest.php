<?php

declare(strict_types=1);

namespace App\Modules\LokasiKantor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLokasiKantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_lokasi' => ['sometimes', 'string', 'max:50'],
            'nama_lokasi' => ['sometimes', 'string', 'max:150'],
            'alamat'      => ['sometimes', 'nullable', 'string'],
            'kota'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'latitude'    => ['sometimes', 'nullable', 'numeric'],
            'longitude'   => ['sometimes', 'nullable', 'numeric'],
            'radius'      => ['sometimes', 'integer', 'min:1'],
            'aktif'       => ['sometimes', 'boolean'],
        ];
    }
}
