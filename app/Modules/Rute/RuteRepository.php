<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RuteRepository implements RuteRepositoryInterface {
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator {
        return RuteModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn($q) => $q->where(function($q2) use ($search) {
                $q2->where('nama_rute','like',"%{$search}%")
                   ->orWhere('kode_rute','like',"%{$search}%")
                   ->orWhere('asal','like',"%{$search}%")
                   ->orWhere('tujuan','like',"%{$search}%");
            }))
            ->orderBy('nama_rute')
            ->paginate($limit, ['*'], 'page', $page);
    }
    public function findById(string $id): ?RuteModel {
        return RuteModel::active()->where('id_rute', $id)->first();
    }
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?RuteModel {
        return RuteModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_rute', $kode)
            ->when($excludeId, fn($q) => $q->where('id_rute','!=',$excludeId))
            ->first();
    }
    public function create(array $data): RuteModel { return RuteModel::create($data); }
    public function update(RuteModel $model, array $data): RuteModel { $model->update($data); return $model->fresh(); }
    public function delete(RuteModel $model): void { $model->softDelete(); }
}