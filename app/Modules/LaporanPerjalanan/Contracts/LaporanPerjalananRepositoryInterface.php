<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan\Contracts;

use App\Modules\LaporanPerjalanan\BiayaLainTripModel;
use App\Modules\LaporanPerjalanan\FotoLaporanPerjalananModel;
use App\Modules\LaporanPerjalanan\LaporanPerjalananModel;

interface LaporanPerjalananRepositoryInterface
{
    public function findByTrip(string $idTrip): ?LaporanPerjalananModel;
    public function findById(string $id): ?LaporanPerjalananModel;
    public function findByIdMilik(string $id, string $idPerusahaan): ?LaporanPerjalananModel;
    public function create(array $data): LaporanPerjalananModel;
    public function update(LaporanPerjalananModel $model, array $data): LaporanPerjalananModel;
    public function reload(LaporanPerjalananModel $model): LaporanPerjalananModel;
    public function syncBiayaLain(LaporanPerjalananModel $laporan, array $biayaLain): void;
    public function addFoto(string $idLaporan, array $data): FotoLaporanPerjalananModel;
    public function findFotoById(string $idLaporan, string $idFoto): ?FotoLaporanPerjalananModel;
    public function deleteFoto(FotoLaporanPerjalananModel $foto): void;
    public function tripMilikPerusahaan(string $idTrip, string $idPerusahaan): bool;
}
