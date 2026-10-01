<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan\Requests;

use App\Modules\TipePermintaan\TipePermintaanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTipePermintaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_tipe'  => ['sometimes', 'string', 'max:100'],
            'jenis_form' => ['sometimes', 'string', Rule::in(TipePermintaanService::JENIS_FORM)],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
