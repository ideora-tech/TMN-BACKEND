<?php

declare(strict_types=1);

namespace App\Modules\Proyek\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStatusProyekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:draft,aktif,selesai,batal'],
        ];
    }
}
