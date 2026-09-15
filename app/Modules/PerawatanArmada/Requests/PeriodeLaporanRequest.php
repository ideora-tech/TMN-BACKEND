<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PeriodeLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_dari'   => ['nullable', 'date_format:Y-m-d'],
            'tanggal_sampai' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($this->filled('tanggal_dari'), ['after_or_equal:tanggal_dari']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal_dari.date_format'         => 'Format tanggal dari harus YYYY-MM-DD',
            'tanggal_sampai.date_format'       => 'Format tanggal sampai harus YYYY-MM-DD',
            'tanggal_sampai.after_or_equal'    => 'Tanggal sampai tidak boleh sebelum tanggal dari',
        ];
    }

    public function dari(): ?string
    {
        return $this->validated('tanggal_dari');
    }

    public function sampai(): ?string
    {
        return $this->validated('tanggal_sampai');
    }
}
