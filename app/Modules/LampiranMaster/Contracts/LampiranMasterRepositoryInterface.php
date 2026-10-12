<?php

declare(strict_types=1);

namespace App\Modules\LampiranMaster\Contracts;

interface LampiranMasterRepositoryInterface
{
    public const JENIS_VENDOR   = 'vendor';
    public const JENIS_SUPPLIER = 'supplier';

    public function pemilikAda(string $jenis, string $idPemilik, string $idPerusahaan): bool;

    public function kunciPemilik(string $jenis, string $idPemilik): void;

    public function listByPemilik(string $jenis, string $idPemilik): array;

    public function hitung(string $jenis, string $idPemilik): int;

    public function urutanTerakhir(string $jenis, string $idPemilik): int;

    public function insert(array $data): void;

    public function find(string $jenis, string $idPemilik, string $idLampiran): ?object;

    public function softDelete(string $idLampiran): void;
}
