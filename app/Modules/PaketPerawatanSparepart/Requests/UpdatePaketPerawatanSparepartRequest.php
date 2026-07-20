<?php
// app/Modules/PaketPerawatanSparepart/Requests/UpdatePaketPerawatanSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaketPerawatanSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_perawatan' => ['sometimes', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['sometimes', 'string', 'max:36'],
            'id_sparepart'       => ['sometimes', 'string', 'max:36'],
            'qty_standar'        => ['sometimes', 'integer', 'min:1'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
