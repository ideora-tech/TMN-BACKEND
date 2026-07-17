<?php

declare(strict_types=1);

namespace App\Modules\DokumenArmada;

use App\Modules\DokumenArmada\Contracts\DokumenArmadaRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DokumenArmadaRepository implements DokumenArmadaRepositoryInterface
{
    private const COLUMNS = [
        'dokumen_armada.id_dokumen_armada', 'dokumen_armada.id_armada', 'dokumen_armada.jenis_dokumen',
        'dokumen_armada.nomor', 'dokumen_armada.berlaku_sampai', 'dokumen_armada.url_file',
        'dokumen_armada.dibuat_pada', 'dokumen_armada.dibuat_oleh',
        'dokumen_armada.diubah_pada', 'dokumen_armada.diubah_oleh',
        'dokumen_armada.dihapus_pada', 'dokumen_armada.dihapus_oleh',
    ];

    public function paginateByArmada(string $idArmada, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('dokumen_armada')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $idArmada)
            ->orderBy('berlaku_sampai')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $jenisDokumen): LengthAwarePaginator
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->whereNull('armada.dihapus_pada')
            ->when($idArmada, fn ($q, $v) => $q->where('dokumen_armada.id_armada', $v))
            ->when($jenisDokumen, fn ($q, $v) => $q->where('dokumen_armada.jenis_dokumen', $v))
            ->orderBy('dokumen_armada.berlaku_sampai')
            ->select(array_merge(self::COLUMNS, ['armada.nopol as armada_nopol', 'armada.merk as armada_merk']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('dokumen_armada')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_dokumen_armada', $id)
            ->first();
    }

    public function findExpiring(string $idPerusahaan, int $days): array
    {
        return DB::table('dokumen_armada')
            ->join('armada', 'armada.id_armada', '=', 'dokumen_armada.id_armada')
            ->where('armada.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_armada.dihapus_pada')
            ->whereNotNull('dokumen_armada.berlaku_sampai')
            ->where('dokumen_armada.berlaku_sampai', '<=', now()->addDays($days))
            ->select(self::COLUMNS)
            ->get()
            ->all();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_dokumen_armada');
        DB::table('dokumen_armada')->insert($data);
        return $this->findById($data['id_dokumen_armada']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('dokumen_armada')
            ->where('id_dokumen_armada', $record->id_dokumen_armada)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_dokumen_armada);
    }

    public function delete(object $record): void
    {
        DB::table('dokumen_armada')
            ->where('id_dokumen_armada', $record->id_dokumen_armada)
            ->update(RecordHelper::stampDelete());
    }
}
