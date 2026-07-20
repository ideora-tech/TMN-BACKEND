<?php
// app/Modules/KategoriSparepart/Requests/UpdateKategoriSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKategoriSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['sometimes', 'string', 'max:100'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
