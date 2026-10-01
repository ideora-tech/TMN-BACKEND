<?php

declare(strict_types=1);

namespace App\Modules\JudulPermintaan;

use App\Modules\JudulPermintaan\Contracts\JudulPermintaanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JudulPermintaanRepository implements JudulPermintaanRepositoryInterface
{
    private const COLUMNS = [
        'id_judul_permintaan', 'id_perusahaan', 'nama_judul', 'tipe', 'id_tipe_permintaan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator
    {
        $query = DB::table('judul_permintaan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where('nama_judul', 'like', "%{$search}%");
        }

        if ($aktif !== null) {
            $query->where('aktif', $aktif ? 1 : 0);
        }

        $hasil = $query->orderBy('nama_judul')->paginate($limit, self::COLUMNS, 'page', $page);
        $this->lampirkanNamaTipe($hasil->getCollection()->all());

        return $hasil;
    }

    public function listAktifByPerusahaan(string $idPerusahaan): array
    {
        $rows = DB::table('judul_permintaan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->orderBy('nama_judul')
            ->get()
            ->all();
        $this->lampirkanNamaTipe($rows);

        return $rows;
    }

    public function findById(string $id): ?object
    {
        $record = DB::table('judul_permintaan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_judul_permintaan', $id)
            ->first();
        if ($record !== null) {
            $this->lampirkanNamaTipe([$record]);
        }

        return $record;
    }

    public function tipePermintaanMilik(string $idTipePermintaan, string $idPerusahaan): ?object
    {
        return DB::table('tipe_permintaan')
            ->whereNull('dihapus_pada')
            ->where('id_tipe_permintaan', $idTipePermintaan)
            ->where('id_perusahaan', $idPerusahaan)
            ->first(['id_tipe_permintaan', 'jenis_form', 'aktif']);
    }

    public function tipePermintaanBawaan(string $idPerusahaan, string $jenisForm): ?object
    {
        return DB::table('tipe_permintaan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('jenis_form', $jenisForm)
            ->where('aktif', 1)
            ->orderBy('dibuat_pada')
            ->first(['id_tipe_permintaan', 'jenis_form', 'aktif']);
    }

    private function lampirkanNamaTipe(array $rows): void
    {
        $idTipe = array_values(array_unique(array_filter(array_map(fn ($r) => $r->id_tipe_permintaan, $rows))));
        $nama = $idTipe === []
            ? collect()
            : DB::table('tipe_permintaan')->whereIn('id_tipe_permintaan', $idTipe)->pluck('nama_tipe', 'id_tipe_permintaan');

        foreach ($rows as $row) {
            $row->nama_tipe = $row->id_tipe_permintaan !== null ? ($nama[$row->id_tipe_permintaan] ?? null) : null;
        }
    }

    public function findByNama(string $idPerusahaan, string $nama, ?string $kecualiId = null): ?object
    {
        return DB::table('judul_permintaan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->whereRaw('LOWER(nama_judul) = ?', [mb_strtolower($nama)])
            ->when($kecualiId !== null, fn ($q) => $q->where('id_judul_permintaan', '!=', $kecualiId))
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_judul_permintaan');
        DB::table('judul_permintaan')->insert($data);
        return $this->findById($data['id_judul_permintaan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('judul_permintaan')
            ->where('id_judul_permintaan', $record->id_judul_permintaan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_judul_permintaan);
    }

    public function delete(object $record): void
    {
        DB::table('judul_permintaan')
            ->where('id_judul_permintaan', $record->id_judul_permintaan)
            ->update(RecordHelper::stampDelete());
    }

    public function dipakaiPermintaanPembelian(string $idJudulPermintaan): bool
    {
        return DB::table('permintaan_pembelian')
            ->whereNull('dihapus_pada')
            ->where('id_judul_permintaan', $idJudulPermintaan)
            ->exists();
    }
}
