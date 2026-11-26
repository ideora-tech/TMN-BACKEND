<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\KategoriPemasukan\KategoriPemasukanService;
use Illuminate\Validation\Rule;

class StorePemasukanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'    => ['required', 'string', $this->kategoriValid()],
            'nominal'     => ['required', 'numeric', 'min:0.01'],
            'tanggal'     => ['required', 'date'],
            'sumber_dana' => ['required', 'string', 'max:150'],
            'keterangan'  => ['nullable', 'string', 'max:255'],
            'bukti'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /** Kategori harus terdaftar dan aktif di master Kategori Pemasukan. */
    private function kategoriValid(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $idPerusahaan = (string) $this->user()?->id_perusahaan;
            if (!is_string($value) || !app(KategoriPemasukanService::class)->kodeBolehDipakai($idPerusahaan, $value, null)) {
                $fail('Kategori pemasukan tidak terdaftar atau sudah nonaktif');
            }
        };
    }
}
