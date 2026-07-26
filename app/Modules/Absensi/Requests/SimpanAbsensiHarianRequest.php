<?php

declare(strict_types=1);

namespace App\Modules\Absensi\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanAbsensiHarianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'               => ['required', 'date'],
            'entries'               => ['required', 'array', 'min:1'],
            'entries.*.id_karyawan' => ['required', 'string', 'uuid'],
            'entries.*.status'      => ['present', 'nullable', 'string', 'in:hadir,terlambat,izin,sakit,alpha'],
            'entries.*.jam_masuk'   => ['sometimes', 'nullable', 'date_format:H:i'],
            'entries.*.jam_pulang'  => ['sometimes', 'nullable', 'date_format:H:i'],
            'entries.*.keterangan'  => ['sometimes', 'nullable', 'string'],
        ];
    }
}
