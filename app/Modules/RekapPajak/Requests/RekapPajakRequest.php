<?php

declare(strict_types=1);

namespace App\Modules\RekapPajak\Requests;

use App\Modules\RekapPajak\RekapPajakService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RekapPajakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dari'   => ['nullable', 'date_format:Y-m'],
            'sampai' => ['nullable', 'date_format:Y-m'],
            'jenis'  => ['nullable', 'string', Rule::in(array_keys(RekapPajakService::LABEL_JENIS))],
            'search' => ['nullable', 'string', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'dari.date_format'   => 'Format masa pajak awal harus YYYY-MM',
            'sampai.date_format' => 'Format masa pajak akhir harus YYYY-MM',
            'jenis.in'           => 'Jenis pajak tidak dikenal',
        ];
    }

    public function teks(string $kunci): ?string
    {
        $nilai = $this->validated()[$kunci] ?? null;

        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }

    public function halaman(): int
    {
        return max(1, (int) ($this->validated()['page'] ?? 1));
    }

    public function batas(): int
    {
        return min(100, max(1, (int) ($this->validated()['limit'] ?? 10)));
    }
}
