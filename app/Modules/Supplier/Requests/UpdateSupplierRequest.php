<?php
declare(strict_types=1);

namespace App\Modules\Supplier\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'    => ['sometimes', 'string', 'max:150'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'alamat'  => ['nullable', 'string'],
            'aktif'   => ['sometimes', 'boolean'],
        ];
    }
}
