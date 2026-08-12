<?php

declare(strict_types=1);

namespace App\Modules\Rekonsiliasi;

use App\Modules\Rekonsiliasi\Contracts\RekonsiliasiRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class RekonsiliasiRepository implements RekonsiliasiRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        return RekonsiliasiModel::active()
            ->join('faktur', 'rekonsiliasi.id_faktur', '=', 'faktur.id_faktur')
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->whereNull('faktur.dihapus_pada')
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('faktur.nomor_faktur', 'like', "%{$search}%")
                   ->orWhere('rekonsiliasi.catatan_klien', 'like', "%{$search}%")
                   ->orWhere('rekonsiliasi.catatan_keuangan', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q, $v) => $q->where('rekonsiliasi.status', $v))
            ->select('rekonsiliasi.*')
            ->with('faktur:id_faktur,nomor_faktur')
            ->orderBy('rekonsiliasi.dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByFaktur(string $idFaktur, int $page, int $limit): LengthAwarePaginator
    {
        return RekonsiliasiModel::active()
            ->where('id_faktur', $idFaktur)
            ->with('faktur:id_faktur,nomor_faktur')
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?RekonsiliasiModel
    {
        return RekonsiliasiModel::active()->with('faktur:id_faktur,nomor_faktur')->find($id);
    }

    public function create(array $data): RekonsiliasiModel
    {
        return RekonsiliasiModel::create($data);
    }

    public function update(RekonsiliasiModel $model, array $data): RekonsiliasiModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(RekonsiliasiModel $model): void
    {
        $model->softDelete();
    }
}
