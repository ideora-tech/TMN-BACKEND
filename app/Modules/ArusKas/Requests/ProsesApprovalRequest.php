<?php

declare(strict_types=1);

namespace App\Modules\ArusKas\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProsesApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keputusan' => ['required', 'in:setuju,tolak'],
            'catatan'   => ['nullable', 'string', 'max:255', 'required_if:keputusan,tolak'],
        ];
    }
}
