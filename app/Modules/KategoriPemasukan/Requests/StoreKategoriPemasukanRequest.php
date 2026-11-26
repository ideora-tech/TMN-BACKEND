<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan\Requests;

use App\Modules\KategoriPemasukan\KategoriPemasukanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKategoriPemasukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'     => ['required', 'string', 'max:100'],
            'pos_psak' => ['required', 'string', Rule::in(array_keys(KategoriPemasukanService::POS_PSAK))],
            'aktif'    => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'pos_psak.required' => 'Pilih baris laporan arus kas untuk kategori ini',
            'pos_psak.in'       => 'Baris laporan arus kas tidak valid',
        ];
    }
}
