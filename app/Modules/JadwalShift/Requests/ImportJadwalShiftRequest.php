<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportJadwalShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek' => ['required', 'string', 'exists:proyek,id_proyek,dihapus_pada,NULL'],
            'file'      => ['required', 'file', 'mimes:xlsx,xls'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File Excel wajib diunggah',
            'file.mimes'    => 'File harus berformat Excel (.xlsx / .xls)',
        ];
    }
}
