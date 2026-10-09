<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Requests;

use App\Modules\Karyawan\KaryawanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KoreksiRiwayatJabatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_efektif' => ['required', 'date_format:Y-m-d'],
            'jenis'           => ['sometimes', 'nullable', 'string', Rule::in(KaryawanService::JENIS_PERUBAHAN_JABATAN)],
            'nomor_sk'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'keterangan'      => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal_efektif.required'    => 'Tanggal efektif wajib diisi',
            'tanggal_efektif.date_format' => 'Format tanggal efektif tidak valid',
            'jenis.in'                    => 'Jenis perubahan jabatan tidak dikenal',
        ];
    }
}
