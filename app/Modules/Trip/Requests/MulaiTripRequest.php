<?php

declare(strict_types=1);

namespace App\Modules\Trip\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MulaiTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_penugasan' => ['required', 'string', 'exists:penugasan,id_penugasan,dihapus_pada,NULL'],
            'id_rute'      => ['sometimes', 'nullable', 'string', 'exists:rute,id_rute,dihapus_pada,NULL'],
            'catatan'      => ['sometimes', 'nullable', 'string'],
        ];
    }
}
