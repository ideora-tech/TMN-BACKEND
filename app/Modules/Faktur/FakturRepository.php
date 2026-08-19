<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FakturRepository implements FakturRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $query = FakturModel::active()
            ->leftJoin('klien', function ($join) {
                $join->on('klien.id_klien', '=', 'faktur.id_klien')
                    ->whereNull('klien.dihapus_pada');
            })
            ->where('faktur.id_perusahaan', $idPerusahaan)
            ->select('faktur.*')
            ->orderBy('faktur.dibuat_pada', 'desc');

        if ($status !== null && $status !== '') {
            $query->where('faktur.status', $status);
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('faktur.nomor_faktur', 'like', "%{$search}%")
                  ->orWhere('klien.nama_klien', 'like', "%{$search}%");
            });
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function paginateByKlien(string $idKlien, string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return FakturModel::active()
            ->where('id_klien', $idKlien)
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?FakturModel
    {
        return FakturModel::active()->with('items')->find($id);
    }

    public function namaKlien(string $idKlien, string $idPerusahaan): ?string
    {
        return DB::table('klien')
            ->where('id_klien', $idKlien)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('nama_klien');
    }

    public function namaProyek(string $idProyek, string $idPerusahaan): ?string
    {
        return DB::table('proyek')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->value('nama_proyek');
    }

    public function infoPenawaran(string $idPenawaran, string $idPerusahaan): ?object
    {
        return DB::table('penawaran')
            ->where('id_penawaran', $idPenawaran)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_penawaran', 'nomor_penawaran', 'nilai_penawaran', 'tipe_harga', 'status']);
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function findByNomor(string $nomor, string $idPerusahaan): ?FakturModel
    {
        return FakturModel::active()
            ->where('nomor_faktur', $nomor)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix = 'FK-' . now()->format('Ym') . '-';
        $terakhir = DB::table('faktur')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_faktur', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_faktur');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data): FakturModel
    {
        return FakturModel::create($data);
    }

    public function update(FakturModel $model, array $data): FakturModel
    {
        $model->update($data);
        return $model->fresh(['items']);
    }

    public function delete(FakturModel $model): void
    {
        $model->softDelete();
    }

    public function namaPengguna(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('pengguna')
            ->whereIn('id_pengguna', $ids)
            ->pluck('username', 'id_pengguna')
            ->all();
    }

    public function insertStatusLog(string $idFaktur, string $status, ?string $keterangan = null): void
    {
        DB::table('faktur_status_log')->insert(RecordHelper::stampCreate([
            'id_faktur'  => $idFaktur,
            'status'     => $status,
            'keterangan' => $keterangan,
        ], 'id_log'));
    }

    public function listStatusLog(string $idFaktur): array
    {
        return DB::table('faktur_status_log as l')
            ->leftJoin('pengguna as u', 'u.id_pengguna', '=', 'l.dibuat_oleh')
            ->where('l.id_faktur', $idFaktur)
            ->whereNull('l.dihapus_pada')
            ->orderBy('l.dibuat_pada')
            ->get(['l.status', 'l.keterangan', 'l.dibuat_pada as waktu', 'u.username as oleh'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
