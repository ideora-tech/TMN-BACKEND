<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaldoAwalKasKecilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'    => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nominal'    => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.required'        => 'Tanggal wajib diisi',
            'tanggal.date_format'     => 'Format tanggal harus YYYY-MM-DD',
            'tanggal.before_or_equal' => 'Tanggal saldo awal tidak boleh melewati hari ini',
            'nominal.required'        => 'Nominal saldo awal wajib diisi',
            'nominal.min'             => 'Nominal saldo awal wajib diisi',
            'nominal.max'             => 'Nominal saldo awal maksimal Rp 1.000.000.000',
            'keterangan.max'          => 'Keterangan maksimal 255 karakter',
        ];
    }
}
