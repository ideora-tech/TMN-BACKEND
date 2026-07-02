<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_klien'        => ['sometimes', 'string', 'max:36'],
            'kode_proyek'     => ['sometimes', 'string', 'max:50'],
            'nama_proyek'     => ['sometimes', 'string', 'max:200'],
            'tanggal_mulai'   => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai' => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'status'          => ['sometimes', 'string', 'in:draft,aktif,selesai,batal'],
            'keterangan'      => ['sometimes', 'nullable', 'string'],
        ];
    }
}
