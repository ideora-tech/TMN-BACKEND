<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeeSupirRekapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dari'     => ['nullable', 'date_format:Y-m-d'],
            'sampai'   => ['nullable', 'date_format:Y-m-d'],
            'search'   => ['nullable', 'string', 'max:100'],
            'id_supir' => ['nullable', 'string', 'max:36'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'dari.date_format'   => 'Format tanggal dari harus YYYY-MM-DD',
            'sampai.date_format' => 'Format tanggal sampai harus YYYY-MM-DD',
        ];
    }

    public function filter(): array
    {
        return [
            'dari'     => $this->teks('dari'),
            'sampai'   => $this->teks('sampai'),
            'search'   => $this->teks('search'),
            'id_supir' => $this->teks('id_supir'),
        ];
    }

    public function halaman(): int
    {
        return max(1, (int) ($this->validated()['page'] ?? 1));
    }

    public function batas(): int
    {
        return min(100, max(1, (int) ($this->validated()['limit'] ?? 10)));
    }

    private function teks(string $kunci): ?string
    {
        $nilai = $this->validated()[$kunci] ?? null;

        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }
}
