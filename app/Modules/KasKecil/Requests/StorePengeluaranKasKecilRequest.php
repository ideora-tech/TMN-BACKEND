<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Requests;

use App\Modules\KasKecil\KasKecilService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePengeluaranKasKecilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'     => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'kategori'    => ['required', 'string', Rule::in(array_keys(KasKecilService::KATEGORI))],
            'nominal'     => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'penerima'    => ['nullable', 'string', 'max:150'],
            'keterangan'  => ['required', 'string', 'max:255'],
            'bukti'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'hapus_bukti' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.required'        => 'Tanggal wajib diisi',
            'tanggal.date_format'     => 'Format tanggal harus YYYY-MM-DD',
            'tanggal.before_or_equal' => 'Tanggal pengeluaran tidak boleh melewati hari ini',
            'kategori.required'       => 'Kategori wajib dipilih',
            'kategori.in'             => 'Kategori tidak dikenal',
            'nominal.required'        => 'Nominal wajib diisi',
            'nominal.min'             => 'Nominal wajib diisi',
            'nominal.max'             => 'Nominal maksimal Rp 1.000.000.000',
            'penerima.max'            => 'Dibayar kepada maksimal 150 karakter',
            'keterangan.required'     => 'Keterangan wajib diisi',
            'keterangan.max'          => 'Keterangan maksimal 255 karakter',
            'bukti.mimes'             => 'Bukti harus berupa JPG, PNG, atau PDF',
            'bukti.max'               => 'Ukuran bukti maksimal 5 MB',
        ];
    }
}
