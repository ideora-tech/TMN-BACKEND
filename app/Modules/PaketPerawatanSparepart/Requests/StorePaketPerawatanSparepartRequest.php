<?php
// app/Modules/PaketPerawatanSparepart/Requests/StorePaketPerawatanSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaketPerawatanSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_perawatan' => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
            'id_sparepart'       => ['required', 'string', 'max:36'],
            'qty_standar'        => ['required', 'integer', 'min:1'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
