<?php

declare(strict_types=1);

namespace App\Modules\ParameterTagihanTrip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanParameterTagihanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jumlah_overnight' => ['required', 'integer', 'min:0', 'max:31'],
            'jumlah_add_drop'  => ['required', 'integer', 'min:0', 'max:20'],
            'cross_cluster'    => ['required', 'boolean'],
            'cancellation'     => ['required', 'boolean'],
            'keterangan'       => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'jumlah_overnight.required' => 'Jumlah malam wajib diisi',
            'jumlah_overnight.integer'  => 'Jumlah malam harus berupa angka bulat',
            'jumlah_overnight.min'      => 'Jumlah malam tidak boleh negatif',
            'jumlah_overnight.max'      => 'Jumlah malam maksimal 31',
            'jumlah_add_drop.required'  => 'Jumlah drop tambahan wajib diisi',
            'jumlah_add_drop.integer'   => 'Jumlah drop tambahan harus berupa angka bulat',
            'jumlah_add_drop.min'       => 'Jumlah drop tambahan tidak boleh negatif',
            'jumlah_add_drop.max'       => 'Jumlah drop tambahan maksimal 20',
            'cross_cluster.required'    => 'Pilihan Cross Cluster wajib diisi',
            'cancellation.required'     => 'Pilihan Cancellation wajib diisi',
            'keterangan.max'            => 'Keterangan maksimal 500 karakter',
        ];
    }
}
