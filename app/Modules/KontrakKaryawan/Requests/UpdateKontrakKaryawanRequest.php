<?php

declare(strict_types=1);

namespace App\Modules\KontrakKaryawan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKontrakKaryawanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis_kontrak'   => ['sometimes', 'string', 'in:pkwt,pkwtt,harian,magang,probation'],
            'nomor_kontrak'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'tanggal_mulai'   => ['sometimes', 'date'],
            'tanggal_selesai' => ['sometimes', 'nullable', 'date'],
            'keterangan'      => ['sometimes', 'nullable', 'string'],
            'url_file'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'file'            => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
