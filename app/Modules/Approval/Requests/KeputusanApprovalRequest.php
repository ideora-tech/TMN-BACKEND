<?php

declare(strict_types=1);

namespace App\Modules\Approval\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KeputusanApprovalRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'keputusan' => ['required', Rule::in(['setuju', 'tolak'])],
            'catatan'   => ['required_if:keputusan,tolak', 'nullable', 'string', 'max:1000'],
        ];
    }
}
