<?php

declare(strict_types=1);

namespace App\Modules\Kasbon\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UbahCicilanKasbonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cicilan_per_periode' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'mulai_potong'        => ['required', 'date_format:Y-m'],
            'alasan'              => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'cicilan_per_periode.min'  => 'Cicilan per periode gaji wajib diisi',
            'mulai_potong.required'    => 'Bulan mulai dipotong wajib dipilih',
            'mulai_potong.date_format' => 'Format bulan mulai dipotong harus YYYY-MM',
            'alasan.required'          => 'Alasan perubahan wajib diisi',
        ];
    }
}
