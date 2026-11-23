<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode\Requests;

use App\Support\KodeOtomatis;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePengaturanKodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Format surat (URUT/PREFIX/BULAN/TAHUN) butuh '/' di prefix, mis. PKS/VM/SLI.
        // Format standar tetap tanpa '/' agar tidak bentrok dengan pemisah '-'.
        // Placeholder ({JENIS}) hanya untuk entitas yang mendukungnya.
        $entitas = (string) $this->route('entitas');
        $segmen  = '[A-Za-z0-9-]+';
        foreach (KodeOtomatis::placeholder($entitas) as $nama) {
            $segmen .= '|\\{' . preg_quote($nama, '/') . '\\}';
        }
        $polaPrefix = KodeOtomatis::format($entitas) === 'surat'
            ? "/^({$segmen})(\\/({$segmen}))*$/"
            : '/^[A-Za-z0-9-]+$/';

        return [
            'prefix'        => ['required', 'string', 'max:20', 'regex:' . $polaPrefix],
            'panjang_digit' => ['required', 'integer', 'between:3,8'],
            'reset'         => ['required', 'string', 'in:tidak,bulanan,tahunan'],
        ];
    }
}
