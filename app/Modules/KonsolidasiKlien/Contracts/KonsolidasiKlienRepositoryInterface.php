<?php

declare(strict_types=1);

namespace App\Modules\KonsolidasiKlien\Contracts;

interface KonsolidasiKlienRepositoryInterface
{
    public function klienInfo(string $idKlien, string $idPerusahaan): ?object;
    public function tripKlien(string $idPerusahaan, string $idKlien, ?string $dari, ?string $sampai, ?string $sumber = null, ?string $idProyek = null): array;
    public function titikDropPerTrip(array $idTrips): array;
    public function biayaTagihanPerTrip(array $idTrips): array;
    public function biayaTagihanDetailPerTrip(array $idTrips): array;
    public function uangJalanTambahanPerTrip(array $idTrips): array;
    public function uangJalanTambahanDetailPerTrip(array $idTrips): array;
    public function namaJenisKendaraanMap(array $ids): array;
}
