<?php

declare(strict_types=1);

namespace App\Modules\KomponenUangJalan;

use App\Modules\KomponenUangJalan\Contracts\KomponenUangJalanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KomponenUangJalanRepository implements KomponenUangJalanRepositoryInterface
{
    private const COLUMNS = [
        'id_komponen_uang_jalan', 'id_perusahaan', 'nama', 'urutan', 'aktif', 'dibuat_pada', 'diubah_pada',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?bool $aktif): LengthAwarePaginator
    {
        $query = DB::table('komponen_uang_jalan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where('nama', 'like', "%{$search}%");
        }

        if ($aktif !== null) {
            $query->where('aktif', $aktif ? 1 : 0);
        }

        return $query->orderBy('urutan')->orderBy('nama')->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function listAktifByPerusahaan(string $idPerusahaan): array
    {
        return DB::table('komponen_uang_jalan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->orderBy('urutan')
            ->orderBy('nama')
            ->get(self::COLUMNS)
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('komponen_uang_jalan')
            ->whereNull('dihapus_pada')
            ->where('id_komponen_uang_jalan', $id)
            ->first(self::COLUMNS);
    }

    public function findByIds(array $ids, string $idPerusahaan): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('komponen_uang_jalan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereIn('id_komponen_uang_jalan', $ids)
            ->get(self::COLUMNS)
            ->keyBy('id_komponen_uang_jalan')
            ->all();
    }

    public function namaTerpakai(string $idPerusahaan, string $nama, ?string $kecualiId): bool
    {
        $query = DB::table('komponen_uang_jalan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)]);

        if ($kecualiId !== null) {
            $query->where('id_komponen_uang_jalan', '!=', $kecualiId);
        }

        return $query->exists();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_komponen_uang_jalan');
        DB::table('komponen_uang_jalan')->insert($data);

        return $this->findById($data['id_komponen_uang_jalan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('komponen_uang_jalan')
            ->where('id_komponen_uang_jalan', $record->id_komponen_uang_jalan)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findById($record->id_komponen_uang_jalan);
    }

    public function delete(object $record): void
    {
        DB::table('komponen_uang_jalan')
            ->where('id_komponen_uang_jalan', $record->id_komponen_uang_jalan)
            ->update(RecordHelper::stampDelete());
    }

    public function pernahAda(string $idPerusahaan): bool
    {
        return DB::table('komponen_uang_jalan')->where('id_perusahaan', $idPerusahaan)->exists();
    }

    public function sudahDipakai(string $id): bool
    {
        return DB::table('proyek_rute_komponen as prk')
            ->join('proyek_rute as pr', 'pr.id_proyek_rute', '=', 'prk.id_proyek_rute')
            ->whereNull('prk.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->where('prk.id_komponen_uang_jalan', $id)
            ->exists()
            || DB::table('uang_jalan_rincian as r')
                ->join('uang_jalan as uj', 'uj.id_uang_jalan', '=', 'r.id_uang_jalan')
                ->whereNull('r.dihapus_pada')
                ->whereNull('uj.dihapus_pada')
                ->where('r.id_komponen_uang_jalan', $id)
                ->exists();
    }
}
