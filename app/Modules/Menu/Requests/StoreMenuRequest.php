<?php

declare(strict_types=1);

namespace App\Modules\Menu\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama_menu'     => ['required', 'string', 'max:100'],
            'path'          => ['sometimes', 'nullable', 'string', 'max:200'],
            'id_menu_induk' => ['sometimes', 'nullable', 'string', 'exists:menu,id_menu'],
            'urutan'        => ['sometimes', 'integer', 'min:0'],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
