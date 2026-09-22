<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor\Requests;

use App\Modules\KetersediaanVendor\KetersediaanVendorService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterKetersediaanVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'             => ['nullable', 'string', 'max:100'],
            'status'             => ['nullable', Rule::in(KetersediaanVendorService::STATUS_VALID)],
            'sumber'             => ['nullable', Rule::in(KetersediaanVendorService::SUMBER_VALID)],
            'id_vendor'          => ['nullable', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['nullable', 'string', 'max:36'],
            'page'               => ['nullable', 'integer', 'min:1'],
            'limit'              => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'   => 'Filter status tidak dikenal',
            'sumber.in'   => 'Filter sumber tidak dikenal',
            'limit.max'   => 'Maksimal 100 data per halaman',
        ];
    }

    public function filter(): array
    {
        $data = $this->validated();

        return array_filter(
            [
                'search'             => isset($data['search']) ? trim($data['search']) : null,
                'status'             => $data['status'] ?? null,
                'sumber'             => $data['sumber'] ?? null,
                'id_vendor'          => $data['id_vendor'] ?? null,
                'id_jenis_kendaraan' => $data['id_jenis_kendaraan'] ?? null,
            ],
            fn ($v) => $v !== null && $v !== '',
        );
    }

    public function halaman(): int
    {
        return (int) ($this->validated()['page'] ?? 1);
    }

    public function batas(): int
    {
        return (int) ($this->validated()['limit'] ?? 10);
    }
}
