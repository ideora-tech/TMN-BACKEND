<?php

declare(strict_types=1);

namespace App\Modules\TipePermintaan;

use App\Modules\TipePermintaan\Contracts\TipePermintaanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TipePermintaanRepository implements TipePermintaanRepositoryInterface
{
    private const COLUMNS = [
        'id_tipe_permintaan', 'id_perusahaan', 'nama_tipe', 'jenis_form', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator
    {
        $query = DB::table('tipe_permintaan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where('nama_tipe', 'like', "%{$search}%");
        }

        if ($aktif !== null) {
            $query->where('aktif', $aktif ? 1 : 0);
        }

        $hasil = $query->orderBy('nama_tipe')->paginate($limit, self::COLUMNS, 'page', $page);
        $this->lampirkanJumlahJudul($hasil->getCollection()->all());

        return $hasil;
    }

    public function listAktifByPerusahaan(string $idPerusahaan): array
    {
        return DB::table('tipe_permintaan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->orderBy('nama_tipe')
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        $record = DB::table('tipe_permintaan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_tipe_permintaan', $id)
            ->first();

        if ($record !== null) {
            $this->lampirkanJumlahJudul([$record]);
        }

        return $record;
    }

    public function findByNama(string $idPerusahaan, string $nama, ?string $kecualiId = null): ?object
    {
        return DB::table('tipe_permintaan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('LOWER(nama_tipe) = ?', [mb_strtolower($nama)])
            ->when($kecualiId !== null, fn ($q) => $q->where('id_tipe_permintaan', '!=', $kecualiId))
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_tipe_permintaan');
        DB::table('tipe_permintaan')->insert($data);
        return $this->findById($data['id_tipe_permintaan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('tipe_permintaan')
            ->where('id_tipe_permintaan', $record->id_tipe_permintaan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_tipe_permintaan);
    }

    public function delete(object $record): void
    {
        DB::table('tipe_permintaan')
            ->where('id_tipe_permintaan', $record->id_tipe_permintaan)
            ->update(RecordHelper::stampDelete());
    }

    public function jumlahJudulPemakai(string $idTipePermintaan): int
    {
        return DB::table('judul_permintaan')
            ->whereNull('dihapus_pada')
            ->where('id_tipe_permintaan', $idTipePermintaan)
            ->count();
    }

    public function sinkronJenisFormJudul(string $idTipePermintaan, string $jenisForm): void
    {
        DB::table('judul_permintaan')
            ->whereNull('dihapus_pada')
            ->where('id_tipe_permintaan', $idTipePermintaan)
            ->update(RecordHelper::stampUpdate(['tipe' => $jenisForm]));
    }

    private function lampirkanJumlahJudul(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $jumlah = DB::table('judul_permintaan')
            ->whereNull('dihapus_pada')
            ->whereIn('id_tipe_permintaan', array_map(fn ($r) => $r->id_tipe_permintaan, $rows))
            ->groupBy('id_tipe_permintaan')
            ->selectRaw('id_tipe_permintaan, COUNT(*) as jumlah')
            ->pluck('jumlah', 'id_tipe_permintaan');

        foreach ($rows as $row) {
            $row->jumlah_judul = (int) ($jumlah[$row->id_tipe_permintaan] ?? 0);
        }
    }
}
