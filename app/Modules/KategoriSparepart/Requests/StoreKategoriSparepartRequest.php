<?php
// app/Modules/KategoriSparepart/Requests/StoreKategoriSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKategoriSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'       => ['required', 'string', 'max:100'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
            'aktif'      => ['sometimes', 'boolean'],
        ];
    }
}
