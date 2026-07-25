<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Modules\Notifikasi\Contracts\NotifikasiRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class NotifikasiRepository implements NotifikasiRepositoryInterface
{
    public function paginateForUser(string $idPengguna, string $idPerusahaan, int $page, int $limit, ?string $tipe, ?int $dibaca): LengthAwarePaginator
    {
        $paginator = NotifikasiModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where(function ($q) use ($idPengguna) {
                $q->where('id_pengguna', $idPengguna)->orWhereNull('id_pengguna');
            })
            ->when($tipe, fn ($q) => $q->where('tipe', $tipe))
            ->when($dibaca !== null, fn ($q) => $q->where('dibaca', $dibaca))
            ->orderBy('dibuat_pada', 'DESC')
            ->paginate($limit, ['*'], 'page', $page);

        $paginator->getCollection()->each(fn (NotifikasiModel $n) => $this->attachLink($n));

        return $paginator;
    }

    public function findById(string $id): ?NotifikasiModel
    {
        $n = NotifikasiModel::active()->where('id_notifikasi', $id)->first();
        return $n ? $this->attachLink($n) : null;
    }

    /**
     * Resolve halaman tujuan saat notifikasi diklik, berdasarkan referensi_tipe.
     * dokumen_armada & perawatan_armada diarahkan ke halaman detail armada (bukan
     * record itu sendiri) karena itu yang punya tampilan riwayat + banner terkait.
     */
    private function resolveLink(NotifikasiModel $n): ?string
    {
        if (!$n->referensi_id) {
            return null;
        }

        return match ($n->referensi_tipe) {
            'trip'             => "/trip/{$n->referensi_id}",
            'perawatan_armada' => $this->linkViaColumn('perawatan_armada', 'id_perawatan', $n->referensi_id, 'id_armada', '/armada'),
            'dokumen_armada'   => $this->linkViaColumn('dokumen_armada', 'id_dokumen_armada', $n->referensi_id, 'id_armada', '/armada'),
            'dokumen_vendor'   => $this->linkViaColumn('dokumen_vendor', 'id_dokumen_vendor', $n->referensi_id, 'id_vendor', '/vendor'),
            default            => null,
        };
    }

    private function linkViaColumn(string $table, string $keyColumn, string $keyValue, string $targetColumn, string $routePrefix): ?string
    {
        $targetId = DB::table($table)->where($keyColumn, $keyValue)->value($targetColumn);
        return $targetId ? "{$routePrefix}/{$targetId}" : null;
    }

    private function attachLink(NotifikasiModel $n): NotifikasiModel
    {
        $n->link = $this->resolveLink($n);
        return $n;
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
        return $this->attachLink($model->fresh());
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