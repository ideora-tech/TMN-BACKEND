<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RuteRepository implements RuteRepositoryInterface {
    private const COLUMNS = [
        'id_rute', 'id_perusahaan', 'kode_rute', 'nama_rute', 'asal', 'tujuan',
        'id_lokasi_asal', 'id_lokasi_tujuan', 'estimasi_jarak_km', 'estimasi_durasi_menit',
        'keterangan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator {
        return DB::table('rute')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn($q) => $q->where(function($q2) use ($search) {
                $q2->where('nama_rute','like',"%{$search}%")
                   ->orWhere('kode_rute','like',"%{$search}%")
                   ->orWhere('asal','like',"%{$search}%")
                   ->orWhere('tujuan','like',"%{$search}%");
            }))
            ->orderBy('nama_rute')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object {
        return DB::table('rute')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_rute', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object {
        return DB::table('rute')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_rute', $kode)
            ->when($excludeId, fn($q) => $q->where('id_rute','!=',$excludeId))
            ->first();
    }

    public function create(array $data): object {
        $data = RecordHelper::stampCreate($data, 'id_rute');
        DB::table('rute')->insert($data);
        return $this->findById($data['id_rute']);
    }

    public function update(object $record, array $data): object {
        DB::table('rute')
            ->where('id_rute', $record->id_rute)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_rute);
    }

    public function delete(object $record): void {
        DB::table('rute')
            ->where('id_rute', $record->id_rute)
            ->update(RecordHelper::stampDelete());
    }
}