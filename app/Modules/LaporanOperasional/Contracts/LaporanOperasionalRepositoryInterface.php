<?php

declare(strict_types=1);

namespace App\Modules\LaporanOperasional\Contracts;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

interface LaporanOperasionalRepositoryInterface
{
    /**
     * Query builder baris trip berfilter (belum di-paginate/get).
     */
    public function queryTrip(string $idPerusahaan, array $filter): Builder;

    /**
     * Ringkasan {jumlah_trip, total_biaya} untuk filter yang sama dengan queryTrip.
     */
    public function ringkasanTrip(string $idPerusahaan, array $filter): array;

    /**
     * Master karyawan aktif milik perusahaan, untuk export.
     */
    public function karyawanAktif(string $idPerusahaan): Collection;

    /**
     * Master armada aktif milik perusahaan, untuk export.
     */
    public function armadaAktif(string $idPerusahaan): EloquentCollection;
}
