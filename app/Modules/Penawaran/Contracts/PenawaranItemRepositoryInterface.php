<?php

declare(strict_types=1);

namespace App\Modules\Penawaran\Contracts;

use App\Modules\Penawaran\PenawaranItemModel;
use Illuminate\Support\Collection;

interface PenawaranItemRepositoryInterface
{
    /** Item aktif satu penawaran + kolom nama rute/jenis (untuk Resource). */
    public function listByPenawaran(string $idPenawaran): Collection;

    public function create(array $data): PenawaranItemModel;

    public function deleteByPenawaran(string $idPenawaran): void;

    public function ruteMilik(string $id, string $idPerusahaan): ?object;

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;
}
