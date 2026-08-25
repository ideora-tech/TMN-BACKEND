<?php
// app/Modules/Approval/Requests/StoreConfigApproverRequest.php
declare(strict_types=1);

namespace App\Modules\Approval\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConfigApproverRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tipe'        => ['required', Rule::in(['jabatan', 'pengguna'])],
            'id_jabatan'  => ['required_if:tipe,jabatan', 'nullable', 'uuid'],
            'id_pengguna' => ['required_if:tipe,pengguna', 'nullable', 'uuid'],
        ];
    }
}
