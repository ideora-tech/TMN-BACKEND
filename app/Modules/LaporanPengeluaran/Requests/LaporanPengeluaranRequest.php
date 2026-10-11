<?php

declare(strict_types=1);

namespace App\Modules\LaporanPengeluaran\Requests;

use App\Modules\LaporanPengeluaran\LaporanPengeluaranService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaporanPengeluaranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dari'        => ['nullable', 'date_format:Y-m-d'],
            'sampai'      => ['nullable', 'date_format:Y-m-d'],
            'kategori'    => ['nullable', 'string', 'max:30'],
            'sumber_dana' => ['nullable', 'string', Rule::in(array_keys(LaporanPengeluaranService::LABEL_SUMBER_DANA))],
            'search'      => ['nullable', 'string', 'max:100'],
            'page'        => ['nullable', 'integer', 'min:1'],
            'limit'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'dari.date_format'   => 'Format tanggal dari harus YYYY-MM-DD',
            'sampai.date_format' => 'Format tanggal sampai harus YYYY-MM-DD',
            'sumber_dana.in'     => 'Sumber dana tidak dikenal',
        ];
    }

    public function filter(): array
    {
        return [
            'dari'        => $this->teks('dari'),
            'sampai'      => $this->teks('sampai'),
            'kategori'    => $this->teks('kategori'),
            'sumber_dana' => $this->teks('sumber_dana'),
            'search'      => $this->teks('search'),
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
