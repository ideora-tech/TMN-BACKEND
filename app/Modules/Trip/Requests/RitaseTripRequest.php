<?php

declare(strict_types=1);

namespace App\Modules\Trip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RitaseTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_penugasan' => ['required', 'string', 'exists:penugasan,id_penugasan,dihapus_pada,NULL'],
            'id_rute'      => ['sometimes', 'nullable', 'string', 'exists:rute,id_rute,dihapus_pada,NULL'],
            'diminta_oleh' => ['required', 'string', 'max:100'],
            'catatan'      => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_penugasan.required' => 'Penugasan asal wajib dipilih',
            'id_penugasan.exists'   => 'Penugasan tidak ditemukan',
            'id_rute.exists'        => 'Rute tidak ditemukan',
            'diminta_oleh.required' => 'Isi siapa yang meminta ritase tambahan ini',
            'diminta_oleh.max'      => 'Nama peminta maksimal 100 karakter',
            'catatan.max'           => 'Catatan maksimal 300 karakter',
        ];
    }
}
