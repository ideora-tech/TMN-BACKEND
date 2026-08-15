<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePemasukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'    => ['sometimes', 'required', 'string', Rule::in(['pendapatan_jasa', 'penjualan_aset', 'pengembalian_dana', 'modal_pinjaman', 'lainnya'])],
            'nominal'     => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'tanggal'     => ['sometimes', 'required', 'date'],
            'sumber_dana' => ['sometimes', 'required', 'string', 'max:150'],
            'keterangan'  => ['nullable', 'string', 'max:255'],
            'bukti'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
