<?php

declare(strict_types=1);

namespace App\Modules\ParameterTagihanTrip\Contracts;

interface ParameterTagihanTripRepositoryInterface
{
    public function konteksTrip(string $idTrip, string $idPerusahaan): ?object;

    public function kunciTrip(string $idTrip): void;

    public function tripPunyaFakturAktif(string $idTrip): bool;

    public function findByTrip(string $idTrip): ?object;

    public function simpan(string $idTrip, array $data): void;

    public function namaPengguna(?string $idPengguna): ?string;
}
