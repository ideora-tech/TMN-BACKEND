<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupirProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek' => ['required', 'string', 'max:36'],
            'supir'     => ['required', 'array', 'min:1'],
            'supir.*'   => ['required', 'string', 'max:36'],
        ];
    }
}
