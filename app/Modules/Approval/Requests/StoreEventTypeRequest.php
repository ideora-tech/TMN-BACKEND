<?php
// app/Modules/Approval/Requests/StoreEventTypeRequest.php
declare(strict_types=1);

namespace App\Modules\Approval\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventTypeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'kode'          => ['required', 'string', 'max:50'],
            'nama'          => ['required', 'string', 'max:100'],
            'mode_resolusi' => ['required', Rule::in(['pinned', 'relatif'])],
        ];
    }
}
