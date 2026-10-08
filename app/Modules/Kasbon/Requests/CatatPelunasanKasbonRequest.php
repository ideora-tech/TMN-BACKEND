<?php

declare(strict_types=1);

namespace App\Modules\Kasbon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatatPelunasanKasbonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'         => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nominal'         => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'keterangan'      => ['nullable', 'string', 'max:200'],
            'catat_pemasukan' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.date_format'     => 'Format tanggal harus YYYY-MM-DD',
            'tanggal.before_or_equal' => 'Tanggal pelunasan tidak boleh melewati hari ini',
            'nominal.min'             => 'Nominal pelunasan wajib diisi',
        ];
    }
}
