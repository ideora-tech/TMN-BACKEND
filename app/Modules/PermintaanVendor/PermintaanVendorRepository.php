<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor;

use App\Modules\PermintaanVendor\Contracts\PermintaanVendorRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PermintaanVendorRepository implements PermintaanVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $paginator = PermintaanVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nomor_permintaan', 'like', "%{$search}%")
                   ->orWhereIn('id_proyek', function ($sub) use ($search) {
                       $sub->select('id_proyek')
                           ->from('proyek')
                           ->whereNull('dihapus_pada')
                           ->where('nama_proyek', 'like', "%{$search}%");
                   });
            }))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachReferensi($paginator->getCollection());

        return $paginator;
    }

    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?PermintaanVendorModel
    {
        $record = PermintaanVendorModel::active()
            ->where('id_permintaan', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();

        if ($record !== null) {
            $this->attachReferensi(collect([$record]));
        }
        return $record;
    }

    public function findForUpdate(string $id): ?PermintaanVendorModel
    {
        return PermintaanVendorModel::active()
            ->where('id_permintaan', $id)
            ->lockForUpdate()
            ->first();
    }

    private function attachReferensi(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $namaProyek = DB::table('proyek')
            ->whereIn('id_proyek', $records->pluck('id_proyek')->filter()->unique()->values()->all())
            ->pluck('nama_proyek', 'id_proyek');
        $namaJenis = DB::table('jenis_kendaraan')
            ->whereIn('id_jenis_kendaraan', $records->pluck('id_jenis_kendaraan')->filter()->unique()->values()->all())
            ->pluck('nama_jenis', 'id_jenis_kendaraan');
        $nomorKontrak = DB::table('kontrak_vendor')
            ->whereIn('id_kontrak_vendor', $records->pluck('id_kontrak_vendor')->filter()->unique()->values()->all())
            ->pluck('nomor_kontrak', 'id_kontrak_vendor');
        $unitPerPermintaan = $this->unitUntukBanyak($records->pluck('id_permintaan')->unique()->values()->all());

        foreach ($records as $record) {
            $record->nama_proyek = $record->id_proyek !== null ? ($namaProyek[$record->id_proyek] ?? null) : null;
            $record->syncOriginalAttribute('nama_proyek');
            $record->nama_jenis_kendaraan = $record->id_jenis_kendaraan !== null ? ($namaJenis[$record->id_jenis_kendaraan] ?? null) : null;
            $record->syncOriginalAttribute('nama_jenis_kendaraan');
            $record->nomor_kontrak = $record->id_kontrak_vendor !== null ? ($nomorKontrak[$record->id_kontrak_vendor] ?? null) : null;
            $record->syncOriginalAttribute('nomor_kontrak');
            $record->unit_diminta = $unitPerPermintaan[$record->id_permintaan] ?? [];
            $record->syncOriginalAttribute('unit_diminta');
        }
    }

    public function create(array $data, array $unitRows = []): PermintaanVendorModel
    {
        $record = PermintaanVendorModel::create($data);
        $this->replaceUnit((string) $record->id_permintaan, $unitRows);
        $fresh = $record->fresh();
        $this->attachReferensi(collect([$fresh]));
        return $fresh;
    }

    public function update(PermintaanVendorModel $model, array $data, ?array $unitRows = null): PermintaanVendorModel
    {
        $model->update($data);
        if ($unitRows !== null) {
            $this->replaceUnit((string) $model->id_permintaan, $unitRows);
        }
        $fresh = $model->fresh();
        $this->attachReferensi(collect([$fresh]));
        return $fresh;
    }

    public function unitUntukBanyak(array $idPermintaanList): array
    {
        if ($idPermintaanList === []) {
            return [];
        }

        $rows = DB::table('permintaan_vendor_unit')
            ->whereIn('id_permintaan', $idPermintaanList)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_permintaan', 'id_jenis_kendaraan', 'jumlah_unit']);

        $namaJenis = DB::table('jenis_kendaraan')
            ->whereIn('id_jenis_kendaraan', $rows->pluck('id_jenis_kendaraan')->filter()->unique()->values()->all())
            ->pluck('nama_jenis', 'id_jenis_kendaraan');

        return $rows
            ->groupBy('id_permintaan')
            ->map(fn ($grup) => $grup->map(fn ($item) => [
                'id_jenis_kendaraan'   => $item->id_jenis_kendaraan,
                'nama_jenis_kendaraan' => $item->id_jenis_kendaraan !== null ? ($namaJenis[$item->id_jenis_kendaraan] ?? null) : null,
                'jumlah_unit'          => (int) $item->jumlah_unit,
            ])->values()->all())
            ->all();
    }

    public function replaceUnit(string $idPermintaan, array $unitRows): void
    {
        DB::table('permintaan_vendor_unit')
            ->where('id_permintaan', $idPermintaan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        foreach (array_values($unitRows) as $i => $row) {
            DB::table('permintaan_vendor_unit')->insert(RecordHelper::stampCreate([
                'id_permintaan'      => $idPermintaan,
                'id_jenis_kendaraan' => $row['id_jenis_kendaraan'] ?? null,
                'jumlah_unit'        => (int) $row['jumlah_unit'],
                'urutan'             => $i + 1,
            ], 'id_permintaan_unit'));
        }
    }

    public function delete(PermintaanVendorModel $model): void
    {
        $model->softDelete();
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function jenisKendaraanMilikPerusahaan(string $idJenisKendaraan, string $idPerusahaan): bool
    {
        return DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }
}
