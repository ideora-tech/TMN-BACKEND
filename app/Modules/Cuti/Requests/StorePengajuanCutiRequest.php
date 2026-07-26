<?php

declare(strict_types=1);

namespace App\Modules\Cuti\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePengajuanCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_karyawan'     => ['sometimes', 'nullable', 'string', 'uuid'],
            'id_supir'        => ['sometimes', 'nullable', 'string', 'uuid'],
            'id_jenis_cuti'   => ['required', 'string', 'uuid'],
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'alasan'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}
