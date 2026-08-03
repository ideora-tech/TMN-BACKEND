<?php
declare(strict_types=1);

namespace App\Modules\Supplier\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'    => ['required', 'string', 'max:150'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'alamat'  => ['nullable', 'string'],
            'aktif'   => ['sometimes', 'boolean'],
        ];
    }
}
