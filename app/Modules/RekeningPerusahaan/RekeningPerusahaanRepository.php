<?php

declare(strict_types=1);

namespace App\Modules\RekeningPerusahaan;

use App\Modules\RekeningPerusahaan\Contracts\RekeningPerusahaanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RekeningPerusahaanRepository implements RekeningPerusahaanRepositoryInterface
{
    private const COLUMNS = [
        'id_rekening_perusahaan', 'id_perusahaan', 'nama_bank', 'nomor_rekening', 'atas_nama_rekening', 'utama',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->milik($idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('nama_bank', 'like', "%{$search}%")
                    ->orWhere('nomor_rekening', 'like', "%{$search}%")
                    ->orWhere('atas_nama_rekening', 'like', "%{$search}%");
            });
        }

        return $this->urutkan($query)->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('rekening_perusahaan')
            ->whereNull('dihapus_pada')
            ->where('id_rekening_perusahaan', $id)
            ->first(self::COLUMNS);
    }

    public function kunciPerusahaan(string $idPerusahaan): void
    {
        DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->lockForUpdate()->value('id_perusahaan');
    }

    public function jumlah(string $idPerusahaan): int
    {
        return $this->milik($idPerusahaan)->count();
    }

    public function adaUtama(string $idPerusahaan): bool
    {
        return $this->milik($idPerusahaan)->where('utama', 1)->exists();
    }

    public function kembar(string $idPerusahaan, string $namaBank, string $nomorRekening, ?string $kecualiId = null): bool
    {
        return $this->milik($idPerusahaan)
            ->where('nama_bank', $namaBank)
            ->where('nomor_rekening', $nomorRekening)
            ->when($kecualiId, fn ($q, $id) => $q->where('id_rekening_perusahaan', '!=', $id))
            ->exists();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_rekening_perusahaan');
        DB::table('rekening_perusahaan')->insert($data);

        return $this->findById($data['id_rekening_perusahaan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('rekening_perusahaan')
            ->where('id_rekening_perusahaan', $record->id_rekening_perusahaan)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findById($record->id_rekening_perusahaan);
    }

    public function delete(object $record): void
    {
        DB::table('rekening_perusahaan')
            ->where('id_rekening_perusahaan', $record->id_rekening_perusahaan)
            ->update(RecordHelper::stampDelete());
    }

    public function lepasUtama(string $idPerusahaan): void
    {
        $this->milik($idPerusahaan)->where('utama', 1)->update(RecordHelper::stampUpdate(['utama' => 0]));
    }

    public function salinUtamaKePerusahaan(string $idPerusahaan): void
    {
        $utama = $this->milik($idPerusahaan)->where('utama', 1)->first(['nama_bank', 'nomor_rekening', 'atas_nama_rekening']);

        DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->update([
            'nama_bank'          => $utama->nama_bank ?? null,
            'nomor_rekening'     => $utama->nomor_rekening ?? null,
            'atas_nama_rekening' => $utama->atas_nama_rekening ?? null,
        ]);
    }

    private function milik(string $idPerusahaan): Builder
    {
        return DB::table('rekening_perusahaan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan);
    }

    private function urutkan(Builder $query): Builder
    {
        return $query->orderByDesc('utama')->orderBy('nama_bank')->orderBy('nomor_rekening');
    }
}
