<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePembelianSparepartRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'id_supplier'            => ['nullable', 'string', 'max:36'],
            'id_perawatan'           => ['nullable', 'string', 'max:36'],
            'tanggal_pengajuan'      => ['required', 'date'],
            'keterangan'             => ['nullable', 'string'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.id_sparepart'   => ['required', 'string', 'max:36'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'items.*.harga_estimasi' => ['required', 'numeric', 'min:0'],
            'bukti'                  => ['required', 'array', 'min:1', 'max:10'],
            'bukti.*'                => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'bukti.required' => 'Foto atau lampiran pengajuan wajib diunggah minimal satu',
            'bukti.min'      => 'Foto atau lampiran pengajuan wajib diunggah minimal satu',
            'bukti.max'      => 'Maksimal 10 file lampiran',
            'bukti.*.mimes'  => 'Lampiran harus berupa foto (jpg, jpeg, png, webp) atau PDF',
            'bukti.*.max'    => 'Ukuran file maksimal 5 MB',
        ];
    }
}
