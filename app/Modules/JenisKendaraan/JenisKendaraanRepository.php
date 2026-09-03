<?php

declare(strict_types=1);

namespace App\Modules\JenisKendaraan;

use App\Modules\JenisKendaraan\Contracts\JenisKendaraanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JenisKendaraanRepository implements JenisKendaraanRepositoryInterface
{
    private const COLUMNS = [
        'id_jenis_kendaraan', 'id_perusahaan', 'kode_jenis', 'nama_jenis',
        'kapasitas_muatan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator
    {
        $query = DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('kode_jenis', 'like', "%{$search}%")
                  ->orWhere('nama_jenis', 'like', "%{$search}%");
            });
        }

        if ($aktif !== null) {
            $query->where('aktif', $aktif ? 1 : 0);
        }

        return $query->orderBy('nama_jenis')->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('jenis_kendaraan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('jenis_kendaraan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_jenis', $kode)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jenis_kendaraan');
        DB::table('jenis_kendaraan')->insert($data);
        return $this->findById($data['id_jenis_kendaraan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $record->id_jenis_kendaraan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_jenis_kendaraan);
    }

    public function delete(object $record): void
    {
        DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $record->id_jenis_kendaraan)
            ->update(RecordHelper::stampDelete());
    }

    public function dipakaiRelasiAktif(string $idJenisKendaraan): bool
    {
        $tabelPerujuk = [
            'armada', 'armada_vendor', 'penawaran_item', 'proyek_rute',
            'parameter_bok', 'paket_perawatan_sparepart', 'interval_perawatan',
        ];
        foreach ($tabelPerujuk as $tabel) {
            $ada = DB::table($tabel)
                ->whereNull('dihapus_pada')
                ->where('id_jenis_kendaraan', $idJenisKendaraan)
                ->exists();
            if ($ada) {
                return true;
            }
        }
        return false;
    }
}
