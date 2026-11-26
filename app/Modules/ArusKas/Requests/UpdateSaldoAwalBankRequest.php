<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSaldoAwalBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nominal' => ['required', 'numeric', 'min:0', 'max:999999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.before_or_equal' => 'Tanggal saldo awal tidak boleh di masa depan',
            'nominal.min'             => 'Saldo awal tidak boleh negatif',
        ];
    }
}
