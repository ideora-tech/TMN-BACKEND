<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan\Requests;

use App\Modules\KategoriPemasukan\KategoriPemasukanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKategoriPemasukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'     => ['sometimes', 'required', 'string', 'max:100'],
            'pos_psak' => ['sometimes', 'required', 'string', Rule::in(array_keys(KategoriPemasukanService::POS_PSAK))],
            'aktif'    => ['sometimes', 'boolean'],
        ];
    }
}
