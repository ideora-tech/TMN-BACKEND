<?php

declare(strict_types=1);

namespace App\Modules\TipePembayaran;

use App\Modules\TipePembayaran\Contracts\TipePembayaranRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TipePembayaranRepository implements TipePembayaranRepositoryInterface
{
    private const COLUMNS = [
        'id_tipe_pembayaran', 'id_perusahaan', 'kode_tipe', 'nama_tipe', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?bool $aktif = null): LengthAwarePaginator
    {
        $query = DB::table('tipe_pembayaran')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('kode_tipe', 'like', "%{$search}%")
                  ->orWhere('nama_tipe', 'like', "%{$search}%");
            });
        }

        if ($aktif !== null) {
            $query->where('aktif', $aktif ? 1 : 0);
        }

        return $query->orderBy('nama_tipe')->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function listAktifByPerusahaan(string $idPerusahaan): array
    {
        return DB::table('tipe_pembayaran')
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
        return DB::table('tipe_pembayaran')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_tipe_pembayaran', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('tipe_pembayaran')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_tipe', $kode)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_tipe_pembayaran');
        DB::table('tipe_pembayaran')->insert($data);
        return $this->findById($data['id_tipe_pembayaran']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('tipe_pembayaran')
            ->where('id_tipe_pembayaran', $record->id_tipe_pembayaran)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_tipe_pembayaran);
    }

    public function delete(object $record): void
    {
        DB::table('tipe_pembayaran')
            ->where('id_tipe_pembayaran', $record->id_tipe_pembayaran)
            ->update(RecordHelper::stampDelete());
    }

    public function dipakaiInvoiceVendor(string $idPerusahaan, string $kodeTipe): bool
    {
        return DB::table('invoice_vendor')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('tipe_pembayaran', $kodeTipe)
            ->exists();
    }
}
