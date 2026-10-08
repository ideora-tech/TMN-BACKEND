<?php

declare(strict_types=1);

namespace App\Modules\Kasbon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKasbonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_karyawan'         => ['required', 'string', 'size:36'],
            'tanggal'             => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nominal'             => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'cicilan_per_periode' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'mulai_potong'        => ['required', 'date_format:Y-m'],
            'keperluan'           => ['required', 'string', 'max:500'],
            'nama_bank'           => ['nullable', 'string', 'max:100'],
            'nomor_rekening'      => ['nullable', 'string', 'max:50', 'regex:/^[0-9][0-9\s.\-]*$/'],
            'saldo_awal'          => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_karyawan.required'         => 'Karyawan wajib dipilih',
            'tanggal.date_format'          => 'Format tanggal harus YYYY-MM-DD',
            'tanggal.before_or_equal'      => 'Tanggal kasbon tidak boleh melewati hari ini',
            'nominal.min'                  => 'Nominal kasbon wajib diisi',
            'cicilan_per_periode.min'      => 'Cicilan per periode gaji wajib diisi',
            'mulai_potong.required'        => 'Bulan mulai dipotong wajib dipilih',
            'mulai_potong.date_format'     => 'Format bulan mulai dipotong harus YYYY-MM',
            'keperluan.required'           => 'Keperluan wajib diisi',
            'nomor_rekening.regex'         => 'Nomor rekening hanya boleh berisi angka, spasi, titik, atau strip',
        ];
    }
}
