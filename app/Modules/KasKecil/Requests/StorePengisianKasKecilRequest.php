<?php

declare(strict_types=1);

namespace App\Modules\KasKecil\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePengisianKasKecilRequest extends FormRequest
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
            'keterangan' => ['required', 'string', 'max:500'],
            'id_rekening_perusahaan' => ['sometimes', 'nullable', 'string', 'size:36'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.required'        => 'Tanggal wajib diisi',
            'tanggal.date_format'     => 'Format tanggal harus YYYY-MM-DD',
            'tanggal.before_or_equal' => 'Tanggal pengisian tidak boleh melewati hari ini',
            'nominal.required'        => 'Nominal pengisian wajib diisi',
            'nominal.min'             => 'Nominal pengisian wajib diisi',
            'nominal.max'             => 'Nominal pengisian maksimal Rp 1.000.000.000',
            'keterangan.required'     => 'Keterangan wajib diisi',
            'keterangan.max'          => 'Keterangan maksimal 500 karakter',
        ];
    }
}
