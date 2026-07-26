<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PenyesuaianSaldoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_karyawan' => ['sometimes', 'nullable', 'string', 'uuid'],
            'id_supir'    => ['sometimes', 'nullable', 'string', 'uuid'],
            'tahun'       => ['required', 'integer', 'min:2000', 'max:2100'],
            'jumlah_hari' => ['required', 'integer', 'not_in:0', 'min:-365', 'max:365'],
            'keterangan'  => ['sometimes', 'nullable', 'string'],
        ];
    }
}
