<?php

declare(strict_types=1);

namespace App\Modules\TarifRute;

use App\Modules\TarifRute\Contracts\TarifRuteRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TarifRuteRepository implements TarifRuteRepositoryInterface
{
    private const DETAIL_SELECT = [
        'tarif_rute.*',
        'rute.kode_rute',
        'rute.nama_rute',
        'rute.asal',
        'rute.tujuan',
        'jenis_kendaraan.nama_jenis',
        'klien.nama_klien',
    ];

    private function detailQuery()
    {
        return TarifRuteModel::active()
            ->leftJoin('rute', 'rute.id_rute', '=', 'tarif_rute.id_rute')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'tarif_rute.id_jenis_kendaraan')
            ->leftJoin('klien', 'klien.id_klien', '=', 'tarif_rute.id_klien')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, array $filter = []): LengthAwarePaginator
    {
        $query = $this->detailQuery()->where('tarif_rute.id_perusahaan', $idPerusahaan);

        if (!empty($filter['id_rute'])) {
            $query->where('tarif_rute.id_rute', $filter['id_rute']);
        }
        if (!empty($filter['id_jenis_kendaraan'])) {
            $query->where('tarif_rute.id_jenis_kendaraan', $filter['id_jenis_kendaraan']);
        }
        if (!empty($filter['id_klien'])) {
            $filter['id_klien'] === 'umum'
                ? $query->whereNull('tarif_rute.id_klien')
                : $query->where('tarif_rute.id_klien', $filter['id_klien']);
        }
        if (!empty($filter['berlaku'])) {
            $today = now()->toDateString();
            $query->where('tarif_rute.aktif', 1)
                ->where('tarif_rute.tanggal_mulai', '<=', $today)
                ->where(fn ($q) => $q->whereNull('tarif_rute.tanggal_berakhir')
                    ->orWhere('tarif_rute.tanggal_berakhir', '>=', $today));
        }
        if (!empty($filter['search'])) {
            $s = $filter['search'];
            $query->where(fn ($q) => $q->where('rute.nama_rute', 'like', "%{$s}%")
                ->orWhere('rute.kode_rute', 'like', "%{$s}%")
                ->orWhere('jenis_kendaraan.nama_jenis', 'like', "%{$s}%")
                ->orWhere('klien.nama_klien', 'like', "%{$s}%"));
        }

        return $query->orderByDesc('tarif_rute.tanggal_mulai')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?TarifRuteModel
    {
        return TarifRuteModel::active()->find($id);
    }

    public function findDetailById(string $id): ?TarifRuteModel
    {
        return $this->detailQuery()->where('tarif_rute.id_tarif_rute', $id)->first();
    }

    public function findOverlap(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?string $idKlien,
        string $tanggalMulai,
        ?string $tanggalBerakhir,
        ?string $excludeId = null,
    ): Collection {
        return TarifRuteModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_rute', $idRute)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->when($idKlien === null,
                fn ($q) => $q->whereNull('id_klien'),
                fn ($q) => $q->where('id_klien', $idKlien))
            ->when($excludeId !== null, fn ($q) => $q->where('id_tarif_rute', '!=', $excludeId))
            ->where('tanggal_mulai', '<=', $tanggalBerakhir ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('tanggal_berakhir')
                ->orWhere('tanggal_berakhir', '>=', $tanggalMulai))
            ->orderBy('tanggal_mulai')
            ->get();
    }

    public function findBerlaku(
        string $idPerusahaan,
        string $idRute,
        string $idJenisKendaraan,
        ?string $idKlien,
        string $tanggal,
    ): ?TarifRuteModel {
        return $this->detailQuery()
            ->where('tarif_rute.id_perusahaan', $idPerusahaan)
            ->where('tarif_rute.id_rute', $idRute)
            ->where('tarif_rute.id_jenis_kendaraan', $idJenisKendaraan)
            ->when($idKlien === null,
                fn ($q) => $q->whereNull('tarif_rute.id_klien'),
                fn ($q) => $q->where('tarif_rute.id_klien', $idKlien))
            ->where('tarif_rute.aktif', 1)
            ->where('tarif_rute.tanggal_mulai', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('tarif_rute.tanggal_berakhir')
                ->orWhere('tarif_rute.tanggal_berakhir', '>=', $tanggal))
            ->orderByDesc('tarif_rute.tanggal_mulai')
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

    public function klienMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('klien')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_klien', $id)
            ->first();
    }

    public function create(array $data): TarifRuteModel
    {
        return TarifRuteModel::create($data);
    }

    public function update(TarifRuteModel $model, array $data): TarifRuteModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(TarifRuteModel $model): void
    {
        $model->softDelete();
    }
}
