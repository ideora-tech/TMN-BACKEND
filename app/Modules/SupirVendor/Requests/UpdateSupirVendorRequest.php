<?php

declare(strict_types=1);

namespace App\Modules\SupirVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupirVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_vendor'         => ['sometimes', 'string', 'max:36'],
            'id_kontrak_vendor' => ['sometimes', 'nullable', 'string', 'max:36'],
            'nama'              => ['sometimes', 'string', 'max:150'],
            'telepon'           => ['sometimes', 'nullable', 'string', 'max:30'],
            'no_sim'            => ['sometimes', 'nullable', 'string', 'max:50'],
            'masa_berlaku_sim'  => ['sometimes', 'nullable', 'date'],
            'aktif'             => ['sometimes', 'boolean'],
            'id_pengguna'       => ['sometimes', 'nullable', 'string', 'max:36'],
        ];
    }
}
