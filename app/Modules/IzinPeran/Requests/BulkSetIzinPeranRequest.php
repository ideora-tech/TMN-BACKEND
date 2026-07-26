<?php

declare(strict_types=1);

namespace App\Modules\IzinPeran\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetIzinPeranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kode_peran'              => ['required', 'string', 'max:50'],
            'permissions'             => ['required', 'array', 'min:1'],
            'permissions.*.id_menu'   => ['required', 'string', 'max:36', 'exists:menu,id_menu,dihapus_pada,NULL'],
            'permissions.*.aksi'      => ['required', 'string', 'max:50'],
            'permissions.*.diizinkan' => ['required', 'boolean'],
        ];
    }
}
