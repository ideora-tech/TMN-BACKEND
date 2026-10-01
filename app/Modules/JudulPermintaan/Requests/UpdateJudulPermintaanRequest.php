<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan\Requests;

use App\Modules\JudulPermintaan\JudulPermintaanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJudulPermintaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_judul' => ['sometimes', 'string', 'max:150'],
            'id_tipe_permintaan' => ['sometimes', 'string', 'max:36'],
            'tipe'       => ['sometimes', 'string', Rule::in(JudulPermintaanService::TIPE)],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
