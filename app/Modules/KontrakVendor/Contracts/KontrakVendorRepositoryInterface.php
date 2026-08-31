<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor\Contracts;

use App\Modules\KontrakVendor\KontrakVendorModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface KontrakVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null, ?string $search = null): LengthAwarePaginator;
    public function paginateByProyek(string $idPerusahaan, string $idProyek, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?KontrakVendorModel;
    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?KontrakVendorModel;
    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool;
    public function relinkUnitDanSupir(string $idKontrakLama, string $idKontrakBaru): void;
    public function create(array $data): KontrakVendorModel;
    public function update(KontrakVendorModel $model, array $data): KontrakVendorModel;
    public function delete(KontrakVendorModel $model): void;
    public function adaPenugasanUntukKontrak(string $idKontrakVendor): bool;
    public function getNamaVendor(string $idVendor): ?string;
    public function turunkanKeDraftJikaPerluApprovalUlang(string $idKontrak): ?string;
    public function getPerusahaan(string $idPerusahaan): ?object;
    public function adaPenugasanNonFinalUntukArmadaVendor(string $idArmadaVendor): bool;
    public function adaPenugasanNonFinalUntukSupirVendor(string $idSupirVendor): bool;
    public function lepasTautanUnitDanSupir(string $idKontrakVendor): void;
}
