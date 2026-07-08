<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Modules\Notifikasi\Contracts\NotifikasiRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotifikasiRepository implements NotifikasiRepositoryInterface
{
    public function paginateForUser(string $idPengguna, string $idPerusahaan, int $page, int $limit, ?string $tipe, ?int $dibaca): LengthAwarePaginator
    {
        return NotifikasiModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where(function ($q) use ($idPengguna) {
                $q->where('id_pengguna', $idPengguna)->orWhereNull('id_pengguna');
            })
            ->when($tipe, fn ($q) => $q->where('tipe', $tipe))
            ->when($dibaca !== null, fn ($q) => $q->where('dibaca', $dibaca))
            ->orderBy('dibuat_pada', 'DESC')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?NotifikasiModel
    {
        return NotifikasiModel::active()->where('id_notifikasi', $id)->first();
    }

    public function unreadCount(string $idPengguna, string $idPerusahaan): int
    {
        return NotifikasiModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where(function ($q) use ($idPengguna) {
                $q->where('id_pengguna', $idPengguna)->orWhereNull('id_pengguna');
            })
            ->where('dibaca', 0)
            ->count();
    }

    public function create(array $data): NotifikasiModel
    {
        return NotifikasiModel::create($data);
    }

    public function markRead(NotifikasiModel $model): NotifikasiModel
    {
        $model->update(['dibaca' => 1, 'dibaca_pada' => now()]);
        return $model->fresh();
    }

    public function markAllRead(string $idPengguna, string $idPerusahaan): int
    {
        return NotifikasiModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where(function ($q) use ($idPengguna) {
                $q->where('id_pengguna', $idPengguna)->orWhereNull('id_pengguna');
            })
            ->where('dibaca', 0)
            ->update(['dibaca' => 1, 'dibaca_pada' => now()]);
    }
}