<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan\Requests;

use App\Modules\JudulPermintaan\JudulPermintaanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJudulPermintaanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_judul' => ['required', 'string', 'max:150'],
            'id_tipe_permintaan' => ['required_without:tipe', 'nullable', 'string', 'max:36'],
            'tipe'       => ['required_without:id_tipe_permintaan', 'nullable', 'string', Rule::in(JudulPermintaanService::TIPE)],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
