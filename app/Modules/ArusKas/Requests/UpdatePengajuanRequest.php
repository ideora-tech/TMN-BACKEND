<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'          => ['sometimes', 'string', Rule::in(['uang_jalan', 'legalitas', 'perawatan', 'sparepart', 'penggajian', 'pembelian_aset', 'pembayaran_pinjaman', 'lainnya'])],
            'nominal'           => ['sometimes', 'numeric', 'min:0.01'],
            'tanggal_pengajuan' => ['sometimes', 'date'],
            'penerima'          => ['sometimes', 'string', 'max:150'],
            'keterangan'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'bukti'             => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
