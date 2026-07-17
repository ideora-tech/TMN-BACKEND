<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StokSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis'      => ['required', 'in:masuk,penyesuaian'],
            'qty'        => ['required', 'integer', 'not_in:0', 'required_if:jenis,masuk'],
            'harga'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'keterangan' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('jenis') === 'masuk' && (int) $this->input('qty') <= 0) {
                $v->errors()->add('qty', 'Qty barang masuk harus lebih dari 0');
            }
        });
    }
}
