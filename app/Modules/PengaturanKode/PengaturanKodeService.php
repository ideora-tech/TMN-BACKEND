<?php

declare(strict_types=1);

namespace App\Modules\PengaturanKode;

use App\Modules\PengaturanKode\Contracts\PengaturanKodeRepositoryInterface;
use App\Support\KodeOtomatis;

class PengaturanKodeService
{
    public function __construct(private readonly PengaturanKodeRepositoryInterface $repo) {}

    public function list(string $idPerusahaan): array
    {
        $rows = $this->repo->allByPerusahaan($idPerusahaan);

        $hasil = [];
        foreach (KodeOtomatis::DEFAULT as $entitas => $default) {
            $row = $rows[$entitas] ?? null;
            $hasil[] = (object) [
                'entitas'       => $entitas,
                'prefix'        => $row->prefix ?? $default['prefix'],
                'panjang_digit' => (int) ($row->panjang_digit ?? $default['panjang_digit']),
                'reset'         => $row->reset ?? $default['reset'],
                'tersimpan'     => $row !== null,
            ];
        }

        return $hasil;
    }

    public function update(string $idPerusahaan, string $entitas, array $data): object
    {
        if (!array_key_exists($entitas, KodeOtomatis::DEFAULT)) {
            abort(404, 'Entitas kode tidak ditemukan');
        }

        $record = $this->repo->upsert($idPerusahaan, $entitas, $data);

        return (object) [
            'entitas'       => $record->entitas,
            'prefix'        => $record->prefix,
            'panjang_digit' => (int) $record->panjang_digit,
            'reset'         => $record->reset,
            'tersimpan'     => true,
        ];
    }
}
