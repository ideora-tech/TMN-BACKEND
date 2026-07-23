<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerawatanArmadaRepository implements PerawatanArmadaRepositoryInterface
{
    private const COLUMNS = [
        'perawatan_armada.id_perawatan', 'perawatan_armada.id_armada',
        'perawatan_armada.id_jenis_perawatan', 'perawatan_armada.tanggal',
        'perawatan_armada.jenis_perawatan', 'perawatan_armada.biaya', 'perawatan_armada.km_odometer',
        'perawatan_armada.status', 'perawatan_armada.jadwal_servis_berikutnya', 'perawatan_armada.keterangan',
        'perawatan_armada.dibuat_pada', 'perawatan_armada.dibuat_oleh',
        'perawatan_armada.diubah_pada', 'perawatan_armada.diubah_oleh',
        'perawatan_armada.dihapus_pada', 'perawatan_armada.dihapus_oleh',
    ];

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->orderByDesc('tanggal')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status, bool $jatuhTempo = false): LengthAwarePaginator
    {
        $batas = now()->addDays(30)->toDateString();

        return DB::table('perawatan_armada')
            ->join('armada', 'armada.id_armada', '=', 'perawatan_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('perawatan_armada.dihapus_pada')
            ->whereNull('armada.dihapus_pada')
            ->when($idArmada, fn ($q, $v) => $q->where('perawatan_armada.id_armada', $v))
            ->when($status, fn ($q, $v) => $q->where('perawatan_armada.status', $v))
            ->when($jatuhTempo, fn ($q) => $q
                ->whereNotNull('perawatan_armada.jadwal_servis_berikutnya')
                ->where('perawatan_armada.jadwal_servis_berikutnya', '<=', $batas)
                ->whereRaw('perawatan_armada.id_perawatan = (
                    SELECT p2.id_perawatan FROM perawatan_armada p2
                    WHERE p2.id_armada = perawatan_armada.id_armada AND p2.dihapus_pada IS NULL
                    ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                    LIMIT 1
                )'))
            ->orderByDesc('perawatan_armada.tanggal')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('perawatan_armada')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_perawatan');
        DB::table('perawatan_armada')->insert($data);
        return $this->findById($data['id_perawatan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('perawatan_armada')
            ->where('id_perawatan', $record->id_perawatan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_perawatan);
    }

    public function delete(object $record): void
    {
        DB::table('perawatan_armada')
            ->where('id_perawatan', $record->id_perawatan)
            ->update(RecordHelper::stampDelete());
    }

    private const LINE_COLUMNS = [
        'id_perawatan_sparepart', 'id_perawatan', 'id_sparepart', 'nama_sparepart', 'qty', 'harga',
    ];

    public function getActiveLines(string $idPerawatan): array
    {
        return DB::table('perawatan_sparepart')
            ->select(self::LINE_COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->orderBy('dibuat_pada')
            ->get()
            ->all();
    }

    public function insertLine(array $data): void
    {
        DB::table('perawatan_sparepart')->insert(RecordHelper::stampCreate($data, 'id_perawatan_sparepart'));
    }

    public function softDeleteLines(string $idPerawatan): void
    {
        DB::table('perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perawatan', $idPerawatan)
            ->update(RecordHelper::stampDelete());
    }

    public function getSparepartForUpdate(string $idSparepart): ?object
    {
        return DB::table('sparepart')
            ->select(['id_sparepart', 'nama', 'stok'])
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->lockForUpdate()
            ->first();
    }

    public function setSparepartStok(string $idSparepart, int $stokBaru): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $idSparepart)
            ->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function insertSparepartMutasi(array $data): void
    {
        DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function getJenisPerawatanNama(string $idJenisPerawatan): ?string
    {
        $nama = DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_jenis_perawatan', $idJenisPerawatan)
            ->value('nama');

        return $nama !== null ? (string) $nama : null;
    }

    public function getLatestPerJenisByArmada(string $idArmada): array
    {
        return DB::table('perawatan_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->where('status', 'selesai')
            ->whereNotNull('id_jenis_perawatan')
            ->whereRaw('id_perawatan = (
                SELECT p2.id_perawatan FROM perawatan_armada p2
                WHERE p2.id_armada = perawatan_armada.id_armada
                  AND p2.id_jenis_perawatan = perawatan_armada.id_jenis_perawatan
                  AND p2.status = \'selesai\'
                  AND p2.dihapus_pada IS NULL
                ORDER BY p2.tanggal DESC, p2.dibuat_pada DESC
                LIMIT 1
            )')
            ->get(['id_jenis_perawatan', 'tanggal', 'jadwal_servis_berikutnya', 'km_odometer'])
            ->all();
    }
}
