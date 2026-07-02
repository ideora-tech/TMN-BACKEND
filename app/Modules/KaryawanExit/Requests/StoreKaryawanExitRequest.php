<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKaryawanExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_karyawan'            => ['required', 'string', 'uuid'],
            'jenis_exit'             => ['required', 'string', 'in:resign,pensiun,phk,meninggal,kontrak_habis'],
            'tanggal_efektif'        => ['required', 'date'],
            'alasan'                 => ['sometimes', 'nullable', 'string'],
            'dapat_direkrut_kembali' => ['sometimes', 'boolean'],
        ];
    }
}
