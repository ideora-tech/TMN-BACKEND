<?php
declare(strict_types=1);
namespace App\Modules\Supir\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupirRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'                => ['required', 'string', 'max:200'],
            'no_sim'              => ['required', 'string', 'max:50'],
            'jenis_sim'           => ['sometimes', 'string', 'max:20'],
            'tgl_kadaluarsa_sim'  => ['sometimes', 'nullable', 'date'],
            'telepon'             => ['sometimes', 'nullable', 'string', 'max:30'],
            'status'              => ['sometimes', 'in:aktif,nonaktif'],
            'foto'                => ['sometimes', 'nullable', 'string', 'max:255'],
            'id_pengguna'         => ['sometimes', 'nullable', 'string', 'max:36'],
        ];
    }
}
