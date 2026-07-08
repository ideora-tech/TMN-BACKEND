<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePenawaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nomor_penawaran'  => ['sometimes', 'string', 'max:50'],
            'judul'            => ['sometimes', 'string', 'max:200'],
            'id_klien'         => ['sometimes', 'nullable', 'string', 'max:36'],
            'nilai_penawaran'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status'           => ['sometimes', 'in:draft,terkirim,negosiasi,disetujui,ditolak'],
            'tanggal_penawaran'=> ['sometimes', 'nullable', 'date'],
            'tanggal_berlaku'  => ['sometimes', 'nullable', 'date'],
            'catatan'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}