<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi\Contracts;

use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotifikasiRepositoryInterface
{
    public function paginateForUser(string $idPengguna, string $idPerusahaan, int $page, int $limit, ?string $tipe, ?int $dibaca): LengthAwarePaginator;
    public function findById(string $id): ?NotifikasiModel;
    public function unreadCount(string $idPengguna, string $idPerusahaan): int;
    public function create(array $data): NotifikasiModel;
    public function markRead(NotifikasiModel $model): NotifikasiModel;
    public function markAllRead(string $idPengguna, string $idPerusahaan): int;
}