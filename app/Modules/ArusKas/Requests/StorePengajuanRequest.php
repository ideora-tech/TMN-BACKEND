<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\KategoriPengajuan\KategoriPengajuanService;
use Illuminate\Validation\Rule;

class StorePengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'          => ['required', 'string', $this->kategoriValid()],
            'nominal'           => ['required', 'numeric', 'min:0.01'],
            'tanggal_pengajuan' => ['required', 'date'],
            'penerima'          => ['required', 'string', 'max:150'],
            'keterangan'        => ['nullable', 'string', 'max:255'],
            'bukti'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /** Kategori non-master yang tetap boleh diajukan lewat API (dipakai klien lama/mobile). */
    private const KATEGORI_TETAP = ['uang_jalan', 'sparepart', 'penggajian'];

    /** Kategori dari master Kategori Pengajuan (aktif) atau kategori tetap. */
    private function kategoriValid(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $idPerusahaan = (string) $this->user()?->id_perusahaan;
            if (!is_string($value) || !app(KategoriPengajuanService::class)->kodeBolehDipakai($idPerusahaan, $value, self::KATEGORI_TETAP, null)) {
                $fail('Kategori pengajuan tidak terdaftar atau sudah nonaktif');
            }
        };
    }
}
