<?php
// app/Modules/Approval/Requests/UpdateEventTypeRequest.php
declare(strict_types=1);

namespace App\Modules\Approval\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventTypeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nama'          => ['sometimes', 'string', 'max:100'],
            'mode_resolusi' => ['sometimes', Rule::in(['pinned', 'relatif'])],
            'aktif'         => ['sometimes', 'boolean'],
        ];
    }
}
