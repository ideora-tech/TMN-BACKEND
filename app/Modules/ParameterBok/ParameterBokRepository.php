<?php

declare(strict_types=1);

namespace App\Modules\ParameterBok;

use App\Modules\ParameterBok\Contracts\ParameterBokRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ParameterBokRepository implements ParameterBokRepositoryInterface
{
    private const DETAIL_SELECT = [
        'parameter_bok.*',
        'jenis_kendaraan.nama_jenis',
        'jenis_bbm.nama_bbm',
    ];

    private function detailQuery()
    {
        return ParameterBokModel::active()
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'parameter_bok.id_jenis_kendaraan')
            ->leftJoin('jenis_bbm', 'jenis_bbm.id_jenis_bbm', '=', 'parameter_bok.id_jenis_bbm')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        return $this->detailQuery()
            ->where('parameter_bok.id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where('jenis_kendaraan.nama_jenis', 'like', "%{$search}%"))
            ->orderBy('jenis_kendaraan.nama_jenis')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?ParameterBokModel
    {
        return ParameterBokModel::active()->find($id);
    }

    public function findDetailById(string $id): ?ParameterBokModel
    {
        return $this->detailQuery()->where('parameter_bok.id_parameter_bok', $id)->first();
    }

    public function findByJenisKendaraan(string $idPerusahaan, string $idJenisKendaraan, ?string $excludeId = null): ?ParameterBokModel
    {
        return ParameterBokModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->when($excludeId !== null, fn ($q) => $q->where('id_parameter_bok', '!=', $excludeId))
            ->first();
    }

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }

    public function create(array $data): ParameterBokModel
    {
        return ParameterBokModel::create($data);
    }

    public function update(ParameterBokModel $model, array $data): ParameterBokModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ParameterBokModel $model): void
    {
        $model->softDelete();
    }
}
