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
            'id_supplier'            => ['required', 'string', 'max:36'],
            'id_perawatan'           => ['nullable', 'string', 'max:36'],
            'tanggal_pengajuan'      => ['required', 'date'],
            'keterangan'             => ['nullable', 'string'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.id_sparepart'   => ['required', 'string', 'max:36'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'items.*.harga_estimasi' => ['required', 'numeric', 'min:0'],
        ];
    }
}
