<?php
// app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartRepository.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart;

use App\Modules\PaketPerawatanSparepart\Contracts\PaketPerawatanSparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaketPerawatanSparepartRepository implements PaketPerawatanSparepartRepositoryInterface
{
    private const DETAIL_SELECT = [
        'paket_perawatan_sparepart.*',
        'jenis_perawatan.nama as nama_jenis_perawatan',
        'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan',
        'sparepart.nama as nama_sparepart',
        'sparepart.satuan as satuan_sparepart',
    ];

    private function detailQuery()
    {
        return DB::table('paket_perawatan_sparepart')
            ->leftJoin('jenis_perawatan', 'jenis_perawatan.id_jenis_perawatan', '=', 'paket_perawatan_sparepart.id_jenis_perawatan')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'paket_perawatan_sparepart.id_jenis_kendaraan')
            ->leftJoin('sparepart', 'sparepart.id_sparepart', '=', 'paket_perawatan_sparepart.id_sparepart')
            ->whereNull('paket_perawatan_sparepart.dihapus_pada')
            ->whereNull('jenis_perawatan.dihapus_pada')
            ->whereNull('jenis_kendaraan.dihapus_pada')
            ->whereNull('sparepart.dihapus_pada')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisPerawatan,
        ?string $idJenisKendaraan,
    ): LengthAwarePaginator {
        return $this->detailQuery()
            ->where('paket_perawatan_sparepart.id_perusahaan', $idPerusahaan)
            ->when($idJenisPerawatan, fn ($q, $v) => $q->where('paket_perawatan_sparepart.id_jenis_perawatan', $v))
            ->when($idJenisKendaraan, fn ($q, $v) => $q->where('paket_perawatan_sparepart.id_jenis_kendaraan', $v))
            ->orderBy('jenis_perawatan.nama')
            ->orderBy('jenis_kendaraan.nama_jenis')
            ->orderBy('sparepart.nama')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('paket_perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_paket_perawatan_sparepart', $id)
            ->first();
    }

    public function findDetailById(string $id): ?object
    {
        return $this->detailQuery()->where('paket_perawatan_sparepart.id_paket_perawatan_sparepart', $id)->first();
    }

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisPerawatan,
        string $idJenisKendaraan,
        string $idSparepart,
        ?string $excludeId = null,
    ): ?object {
        return DB::table('paket_perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_perawatan', $idJenisPerawatan)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where('id_sparepart', $idSparepart)
            ->when($excludeId !== null, fn ($q) => $q->where('id_paket_perawatan_sparepart', '!=', $excludeId))
            ->first();
    }

    public function jenisPerawatanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_perawatan', $id)
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

    public function sparepartMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_sparepart', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_paket_perawatan_sparepart');
        DB::table('paket_perawatan_sparepart')->insert($data);
        return $this->findById($data['id_paket_perawatan_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('paket_perawatan_sparepart')
            ->where('id_paket_perawatan_sparepart', $record->id_paket_perawatan_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_paket_perawatan_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('paket_perawatan_sparepart')
            ->where('id_paket_perawatan_sparepart', $record->id_paket_perawatan_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function resolusiList(string $idPerusahaan, string $idJenisPerawatan, string $idJenisKendaraan): array
    {
        return DB::table('paket_perawatan_sparepart')
            ->join('sparepart', 'sparepart.id_sparepart', '=', 'paket_perawatan_sparepart.id_sparepart')
            ->whereNull('paket_perawatan_sparepart.dihapus_pada')
            ->whereNull('sparepart.dihapus_pada')
            ->where('paket_perawatan_sparepart.id_perusahaan', $idPerusahaan)
            ->where('paket_perawatan_sparepart.id_jenis_perawatan', $idJenisPerawatan)
            ->where('paket_perawatan_sparepart.id_jenis_kendaraan', $idJenisKendaraan)
            ->where('paket_perawatan_sparepart.aktif', 1)
            ->where('sparepart.aktif', 1)
            ->orderBy('sparepart.nama')
            ->get([
                'sparepart.id_sparepart',
                'sparepart.nama as nama_sparepart',
                'sparepart.satuan as satuan_sparepart',
                'sparepart.harga_standar',
                'paket_perawatan_sparepart.qty_standar',
            ])
            ->map(fn ($row) => [
                'id_sparepart'   => $row->id_sparepart,
                'nama_sparepart' => $row->nama_sparepart,
                'satuan_sparepart' => $row->satuan_sparepart,
                'qty_standar'    => (int) $row->qty_standar,
                'harga_standar'  => (float) $row->harga_standar,
            ])
            ->all();
    }
}
