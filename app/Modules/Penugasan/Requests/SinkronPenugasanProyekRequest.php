<?php

declare(strict_types=1);

namespace App\Modules\Penugasan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SinkronPenugasanProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lama_mulai'   => ['required', 'date_format:Y-m-d'],
            'lama_selesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:lama_mulai'],
            'baru_mulai'   => ['required', 'date_format:Y-m-d'],
            'baru_selesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:baru_mulai'],
        ];
    }

    public function messages(): array
    {
        return [
            'lama_mulai.required'         => 'Tanggal mulai lama wajib diisi',
            'baru_mulai.required'         => 'Tanggal mulai baru wajib diisi',
            '*.date_format'               => 'Format tanggal harus YYYY-MM-DD',
            'lama_selesai.after_or_equal' => 'Tanggal selesai lama tidak boleh sebelum tanggal mulai',
            'baru_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai',
        ];
    }
}
