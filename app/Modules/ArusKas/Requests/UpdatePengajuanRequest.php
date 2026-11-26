<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\KategoriPengajuan\KategoriPengajuanService;
use Illuminate\Validation\Rule;

class UpdatePengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kategori'          => ['sometimes', 'string', $this->kategoriValid()],
            'nominal'           => ['sometimes', 'numeric', 'min:0.01'],
            'tanggal_pengajuan' => ['sometimes', 'date'],
            'penerima'          => ['sometimes', 'string', 'max:150'],
            'keterangan'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'bukti'             => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /** Kategori non-master yang tetap boleh diajukan lewat API (dipakai klien lama/mobile). */
    private const KATEGORI_TETAP = ['uang_jalan', 'sparepart', 'penggajian'];

    /** Kategori dari master Kategori Pengajuan (aktif, atau kategori lama saat edit) atau kategori tetap. */
    private function kategoriValid(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $idPerusahaan = (string) $this->user()?->id_perusahaan;
            if (!is_string($value) || !app(KategoriPengajuanService::class)->kodeBolehDipakai($idPerusahaan, $value, self::KATEGORI_TETAP, (string) $value)) {
                $fail('Kategori pengajuan tidak terdaftar atau sudah nonaktif');
            }
        };
    }
}
