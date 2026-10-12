<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir\Requests;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\PengeluaranSupir\PengeluaranSupirService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePengeluaranSupirRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $palingAwal = now()->subDays(PengeluaranSupirService::HARI_MUNDUR)->toDateString();

        return [
            'jenis'              => ['required', 'string', Rule::in(array_keys(ArusKasService::KATEGORI_PENGELUARAN_SUPIR))],
            'id_armada'          => ['nullable', 'string', 'max:36', 'required_without:id_armada_vendor', 'prohibits:id_armada_vendor'],
            'id_armada_vendor'   => ['nullable', 'string', 'max:36'],
            'tanggal'            => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:' . $palingAwal],
            'nominal'            => ['required', 'numeric', 'min:1', 'max:' . PengeluaranSupirService::NOMINAL_MAKSIMAL],
            'keterangan'         => ['nullable', 'string', 'max:500'],
            'nama_bank'          => ['nullable', 'string', 'max:100', 'required_with:nomor_rekening'],
            'nomor_rekening'     => ['nullable', 'string', 'max:50', 'required_with:nama_bank'],
            'atas_nama_rekening' => ['nullable', 'string', 'max:150'],
            'foto'               => ['required', 'array', 'min:1', 'max:' . PengeluaranSupirService::MAKS_FOTO],
            'foto.*'             => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        $maksimal = number_format(PengeluaranSupirService::NOMINAL_MAKSIMAL, 0, ',', '.');

        return [
            'jenis.required'                  => 'Jenis pengeluaran wajib dipilih',
            'jenis.in'                        => 'Jenis pengeluaran tidak dikenal',
            'id_armada.required_without'      => 'Unit wajib dipilih',
            'id_armada.prohibits'             => 'Pilih satu unit saja',
            'tanggal.required'                => 'Tanggal wajib diisi',
            'tanggal.date_format'             => 'Format tanggal harus YYYY-MM-DD',
            'tanggal.before_or_equal'         => 'Tanggal tidak boleh setelah hari ini',
            'tanggal.after_or_equal'          => 'Tanggal paling lama ' . PengeluaranSupirService::HARI_MUNDUR . ' hari ke belakang',
            'nominal.required'                => 'Nominal wajib diisi',
            'nominal.numeric'                 => 'Nominal harus berupa angka',
            'nominal.min'                     => 'Nominal harus lebih dari 0',
            'nominal.max'                     => "Nominal maksimal Rp {$maksimal}",
            'keterangan.max'                  => 'Catatan maksimal 500 karakter',
            'nama_bank.required_with'         => 'Nama bank wajib diisi bila nomor rekening diisi',
            'nomor_rekening.required_with'    => 'Nomor rekening wajib diisi bila nama bank diisi',
            'foto.required'                   => 'Foto nota wajib dilampirkan',
            'foto.array'                      => 'Foto nota wajib dilampirkan',
            'foto.min'                        => 'Foto nota wajib dilampirkan',
            'foto.max'                        => 'Foto nota maksimal ' . PengeluaranSupirService::MAKS_FOTO . ' berkas',
            'foto.*.file'                     => 'Foto nota tidak valid',
            'foto.*.mimes'                    => 'Foto nota harus berformat JPG atau PNG',
            'foto.*.max'                      => 'Ukuran tiap foto maksimal 5 MB',
        ];
    }
}
