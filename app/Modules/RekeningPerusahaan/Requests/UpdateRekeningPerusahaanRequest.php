<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan\Requests;

class UpdateRekeningPerusahaanRequest extends StoreRekeningPerusahaanRequest
{
    public function rules(): array
    {
        return [
            'nama_bank'          => ['sometimes', 'required', 'string', 'max:100'],
            'nomor_rekening'     => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[0-9][0-9\s.\-]*$/'],
            'atas_nama_rekening' => ['sometimes', 'nullable', 'string', 'max:200'],
            'utama'              => ['sometimes', 'boolean'],
        ];
    }
}
