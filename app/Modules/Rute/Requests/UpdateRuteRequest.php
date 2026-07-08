<?php
namespace App\Modules\Rute\Requests;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRuteRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'kode_rute'             => 'sometimes|string|max:50',
            'nama_rute'             => 'sometimes|string|max:200',
            'asal'                  => 'sometimes|nullable|string|max:200',
            'tujuan'                => 'sometimes|nullable|string|max:200',
            'estimasi_jarak_km'     => 'sometimes|nullable|numeric|min:0',
            'estimasi_durasi_menit' => 'sometimes|nullable|integer|min:0',
            'keterangan'            => 'sometimes|nullable|string',
            'aktif'                 => 'sometimes|boolean',
        ];
    }
}