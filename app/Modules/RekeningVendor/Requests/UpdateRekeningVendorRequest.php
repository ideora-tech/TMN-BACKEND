<?php

declare(strict_types=1);

namespace App\Modules\RekeningVendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRekeningVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_bank'      => ['sometimes', 'required', 'string', 'max:100'],
            'nomor_rekening' => ['sometimes', 'required', 'string', 'max:50'],
            'atas_nama'      => ['sometimes', 'required', 'string', 'max:150'],
            'cabang'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'mata_uang'      => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_bank.required'      => 'Nama bank wajib diisi',
            'nomor_rekening.required' => 'Nomor rekening wajib diisi',
            'atas_nama.required'      => 'Atas nama wajib diisi',
        ];
    }
}
