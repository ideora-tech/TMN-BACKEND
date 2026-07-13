<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePenugasanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek'     => ['required', 'string', 'max:36'],
            'id_armada'     => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_supir'      => ['sometimes', 'nullable', 'string', 'max:36'],
            'id_karyawan'   => ['sometimes', 'nullable', 'string', 'max:36'],
            'tanggal_tugas' => ['sometimes', 'nullable', 'date'],
            'status'        => ['sometimes', 'string', 'in:pending,aktif,selesai,batal'],
        ];
    }
}
