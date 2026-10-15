<?php

declare(strict_types=1);

namespace App\Modules\UangJalan\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUangJalanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal'             => ['required', 'date_format:Y-m-d'],
            'tipe_driver'         => ['required', 'in:internal,vendor'],
            'id_supir'            => ['required_if:tipe_driver,internal', 'nullable', 'string', 'size:36'],
            'id_armada'           => ['required_if:tipe_driver,internal', 'nullable', 'string', 'size:36'],
            'id_vendor'           => ['required_if:tipe_driver,vendor', 'nullable', 'string', 'size:36'],
            'id_supir_vendor'     => ['required_if:tipe_driver,vendor', 'nullable', 'string', 'size:36'],
            'id_armada_vendor'    => ['required_if:tipe_driver,vendor', 'nullable', 'string', 'size:36'],
            'id_rute'             => ['required', 'string', 'size:36'],
            'id_proyek'           => ['nullable', 'string', 'size:36', 'required_with:id_penugasan', 'required_without:alasan_tanpa_proyek'],
            'alasan_tanpa_proyek' => ['nullable', 'string', 'min:5', 'max:255', 'required_without:id_proyek'],
            'id_penugasan'        => ['nullable', 'string', 'size:36'],
            'rincian'                          => ['required', 'array', 'min:1', 'max:50'],
            'rincian.*.id_komponen_uang_jalan' => ['required', 'string', 'size:36'],
            'rincian.*.nominal'                => ['required', 'numeric', 'min:0', 'max:100000000'],
            'jumlah_trip'         => ['required', 'integer', 'min:1', 'max:999'],
            'nomor_rekening'      => ['required', 'string', 'max:50', 'regex:/^[0-9][0-9\s.\-]*$/'],
            'nama_bank'           => ['required', 'string', 'max:100'],
            'catatan'             => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_supir.required_if'          => 'Supir wajib dipilih',
            'id_armada.required_if'         => 'Unit wajib dipilih',
            'id_vendor.required_if'         => 'Vendor wajib dipilih',
            'id_supir_vendor.required_if'   => 'Driver vendor wajib dipilih',
            'id_armada_vendor.required_if'  => 'Unit vendor wajib dipilih',
            'id_rute.required'              => 'Rute wajib dipilih',
            'rincian.required'              => 'Isi minimal salah satu komponen uang jalan per trip',
            'rincian.min'                   => 'Isi minimal salah satu komponen uang jalan per trip',
            'id_proyek.required_with'       => 'Pilih proyek terlebih dahulu sebelum memilih penugasan',
            'id_proyek.required_without'    => 'Pilih proyek, atau pilih Di luar proyek dan isi alasannya',
            'alasan_tanpa_proyek.required_without' => 'Alasan di luar proyek wajib diisi',
            'alasan_tanpa_proyek.min'       => 'Alasan di luar proyek minimal 5 karakter',
            'alasan_tanpa_proyek.max'       => 'Alasan di luar proyek maksimal 255 karakter',
            'nomor_rekening.regex'          => 'Nomor rekening hanya boleh berisi angka, spasi, titik, atau strip',
            'tanggal.date_format'           => 'Format tanggal harus YYYY-MM-DD',
        ];
    }
}
