<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KontrakVendorRepository implements KontrakVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null): LengthAwarePaginator
    {
        $paginator = KontrakVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($idVendor, fn ($q) => $q->where('id_vendor', $idVendor))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachNamaVendor($paginator->getCollection());

        return $paginator;
    }

    public function paginateByProyek(string $idPerusahaan, string $idProyek, int $page, int $limit): LengthAwarePaginator
    {
        $paginator = KontrakVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_proyek', $idProyek)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachNamaVendor($paginator->getCollection());

        return $paginator;
    }

    public function findById(string $id): ?KontrakVendorModel
    {
        $record = KontrakVendorModel::active()->find($id);
        if ($record !== null) {
            $this->attachNamaVendor(collect([$record]));
        }
        return $record;
    }

    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?KontrakVendorModel
    {
        $record = KontrakVendorModel::active()
            ->where('id_kontrak_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();

        if ($record !== null) {
            $this->attachNamaVendor(collect([$record]));
        }
        return $record;
    }

    /**
     * Tempel nama_vendor ke tiap record via raw query builder (join manual),
     * bukan Eloquent relationship — hindari overhead & N+1 tersembunyi ala ORM.
     */
    private function attachNamaVendor(Collection $records): void
    {
        $idVendorList = $records->pluck('id_vendor')->filter()->unique()->values()->all();
        if (empty($idVendorList)) {
            return;
        }

        $namaByIdVendor = DB::table('vendor')
            ->whereIn('id_vendor', $idVendorList)
            ->pluck('nama_vendor', 'id_vendor');

        foreach ($records as $record) {
            $record->vendor_nama = $namaByIdVendor[$record->id_vendor] ?? null;
        }
    }

    public function create(array $data): KontrakVendorModel
    {
        return KontrakVendorModel::create($data);
    }

    public function update(KontrakVendorModel $model, array $data): KontrakVendorModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(KontrakVendorModel $model): void
    {
        $model->softDelete();
    }
}
