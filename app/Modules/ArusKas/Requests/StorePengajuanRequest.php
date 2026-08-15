<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'          => ['required', 'string', Rule::in(['uang_jalan', 'legalitas', 'perawatan', 'sparepart', 'penggajian', 'pembelian_aset', 'pembayaran_pinjaman', 'lainnya'])],
            'nominal'           => ['required', 'numeric', 'min:0.01'],
            'tanggal_pengajuan' => ['required', 'date'],
            'penerima'          => ['required', 'string', 'max:150'],
            'keterangan'        => ['nullable', 'string', 'max:255'],
            'bukti'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
