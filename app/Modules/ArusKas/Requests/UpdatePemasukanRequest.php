<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\KategoriPemasukan\KategoriPemasukanService;
use Illuminate\Validation\Rule;

class UpdatePemasukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'    => ['sometimes', 'required', 'string', $this->kategoriValid()],
            'nominal'     => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'tanggal'     => ['sometimes', 'required', 'date'],
            'sumber_dana' => ['sometimes', 'required', 'string', 'max:150'],
            'keterangan'  => ['nullable', 'string', 'max:255'],
            'bukti'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /** Kategori harus terdaftar di master Kategori Pemasukan (kategori nonaktif tetap boleh untuk data lama). */
    private function kategoriValid(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $idPerusahaan = (string) $this->user()?->id_perusahaan;
            if (!is_string($value) || !app(KategoriPemasukanService::class)->kodeBolehDipakai($idPerusahaan, $value, (string) $value)) {
                $fail('Kategori pemasukan tidak terdaftar atau sudah nonaktif');
            }
        };
    }
}
