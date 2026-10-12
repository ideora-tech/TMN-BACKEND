<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRekeningPerusahaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_bank'          => ['required', 'string', 'max:100'],
            'nomor_rekening'     => ['required', 'string', 'max:50', 'regex:/^[0-9][0-9\s.\-]*$/'],
            'atas_nama_rekening' => ['nullable', 'string', 'max:200'],
            'utama'              => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_bank.required'      => 'Nama bank wajib diisi',
            'nama_bank.max'           => 'Nama bank maksimal 100 karakter',
            'nomor_rekening.required' => 'Nomor rekening wajib diisi',
            'nomor_rekening.max'      => 'Nomor rekening maksimal 50 karakter',
            'nomor_rekening.regex'    => 'Nomor rekening hanya boleh berisi angka, spasi, titik, atau strip',
            'atas_nama_rekening.max'  => 'Nama pemilik rekening maksimal 200 karakter',
        ];
    }
}
