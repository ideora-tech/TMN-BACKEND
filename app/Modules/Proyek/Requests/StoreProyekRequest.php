<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_klien'        => ['required', 'string', 'max:36'],
            'kode_proyek'     => ['required', 'string', 'max:50'],
            'nama_proyek'     => ['required', 'string', 'max:200'],
            'tanggal_mulai'   => ['sometimes', 'nullable', 'date'],
            'tanggal_selesai' => ['sometimes', 'nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'status'          => ['sometimes', 'string', 'in:draft,aktif,selesai,batal'],
            'keterangan'      => ['sometimes', 'nullable', 'string'],
        ];
    }
}
