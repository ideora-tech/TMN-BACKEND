<?php

declare(strict_types=1);

namespace App\Modules\UangJalan;

use App\Modules\UangJalan\Contracts\UangJalanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UangJalanRepository implements UangJalanRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $dari = null,
        ?string $sampai = null,
    ): LengthAwarePaginator {
        $query = $this->dasar()->where('uj.id_perusahaan', $idPerusahaan);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('uj.nomor_uang_jalan', 'like', "%{$search}%")
                    ->orWhere('uj.nama_driver', 'like', "%{$search}%")
                    ->orWhere('uj.nama_vendor', 'like', "%{$search}%")
                    ->orWhere('uj.nopol', 'like', "%{$search}%")
                    ->orWhere('uj.rute', 'like', "%{$search}%");
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('pp.status', $status);
        }

        if ($dari !== null && $dari !== '') {
            $query->where('uj.tanggal', '>=', $dari);
        }

        if ($sampai !== null && $sampai !== '') {
            $query->where('uj.tanggal', '<=', $sampai);
        }

        return $query
            ->orderByDesc('uj.tanggal')
            ->orderByDesc('uj.nomor_uang_jalan')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return $this->dasar()->where('uj.id_uang_jalan', $id)->first();
    }

    public function findSupir(string $id, string $idPerusahaan): ?object
    {
        return DB::table('supir')
            ->where('id_supir', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_supir', 'nama']);
    }

    public function findArmada(string $id, string $idPerusahaan): ?object
    {
        return DB::table('armada')
            ->where('id_armada', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kepemilikan', 'internal')
            ->whereNull('dihapus_pada')
            ->first(['id_armada', 'nopol']);
    }

    public function findVendor(string $id, string $idPerusahaan): ?object
    {
        return DB::table('vendor')
            ->where('id_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_vendor', 'nama_vendor']);
    }

    public function findSupirVendor(string $id, string $idVendor): ?object
    {
        return DB::table('supir_vendor')
            ->where('id_supir_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->whereNull('dihapus_pada')
            ->first(['id_supir_vendor', 'nama']);
    }

    public function findArmadaVendor(string $id, string $idVendor): ?object
    {
        return DB::table('armada_vendor')
            ->where('id_armada_vendor', $id)
            ->where('id_vendor', $idVendor)
            ->whereNull('dihapus_pada')
            ->first(['id_armada_vendor', 'nopol']);
    }

    public function findRute(string $id, string $idPerusahaan): ?object
    {
        return DB::table('rute')
            ->where('id_rute', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_rute', 'nama_rute']);
    }

    public function opsiInternal(string $idPerusahaan): array
    {
        $supir = DB::table('supir as s')
            ->leftJoin('karyawan as k', function (JoinClause $join) {
                $join->on('k.id_karyawan', '=', 's.id_karyawan')
                    ->on('k.id_perusahaan', '=', 's.id_perusahaan')
                    ->whereNull('k.dihapus_pada');
            })
            ->where('s.id_perusahaan', $idPerusahaan)
            ->where('s.status', 'aktif')
            ->whereNull('s.dihapus_pada')
            ->orderBy('s.nama')
            ->get(['s.id_supir', 's.nama', 'k.nama_bank', 'k.nomor_rekening'])
            ->all();

        $armada = DB::table('armada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kepemilikan', 'internal')
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nopol')
            ->get(['id_armada', 'nopol', 'merk'])
            ->all();

        $vendor = DB::table('vendor')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_vendor')
            ->get(['id_vendor', 'nama_vendor'])
            ->all();

        $rute = DB::table('rute')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_rute')
            ->get(['id_rute', 'nama_rute'])
            ->all();

        return compact('supir', 'armada', 'vendor', 'rute');
    }

    public function opsiVendor(string $idVendor): array
    {
        $supirVendor = DB::table('supir_vendor')
            ->where('id_vendor', $idVendor)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nama')
            ->get(['id_supir_vendor', 'nama'])
            ->all();

        $armadaVendor = DB::table('armada_vendor')
            ->where('id_vendor', $idVendor)
            ->where('aktif', 1)
            ->whereNull('dihapus_pada')
            ->orderBy('nopol')
            ->get(['id_armada_vendor', 'nopol', 'merk'])
            ->all();

        $rekening = DB::table('rekening_vendor')
            ->where('id_vendor', $idVendor)
            ->whereNull('dihapus_pada')
            ->orderBy('nama_bank')
            ->get(['nama_bank', 'nomor_rekening', 'atas_nama'])
            ->all();

        return ['supir_vendor' => $supirVendor, 'armada_vendor' => $armadaVendor, 'rekening' => $rekening];
    }

    public function nomorBerikutnya(string $idPerusahaan): string
    {
        $prefix   = 'UJ-' . now()->format('Ym') . '-';
        $terakhir = DB::table('uang_jalan')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('nomor_uang_jalan', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('nomor_uang_jalan');
        $urut = $terakhir ? ((int) substr((string) $terakhir, -4)) + 1 : 1;

        return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_uang_jalan');
        DB::table('uang_jalan')->insert($data);

        return $this->findById($data['id_uang_jalan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('uang_jalan')
            ->where('id_uang_jalan', $record->id_uang_jalan)
            ->update(RecordHelper::stampUpdate($data));

        return $this->findById($record->id_uang_jalan);
    }

    public function delete(object $record): void
    {
        DB::table('uang_jalan')
            ->where('id_uang_jalan', $record->id_uang_jalan)
            ->update(RecordHelper::stampDelete());
    }

    private function dasar(): Builder
    {
        return DB::table('uang_jalan as uj')
            ->leftJoin('pengajuan_pengeluaran as pp', function (JoinClause $join) {
                $join->on('pp.id_pengajuan', '=', 'uj.id_pengajuan')->whereNull('pp.dihapus_pada');
            })
            ->whereNull('uj.dihapus_pada')
            ->select([
                'uj.*',
                'pp.nomor_pengajuan',
                'pp.status as status_pengajuan',
                'pp.alasan_ditolak',
                'pp.tanggal_transfer',
            ]);
    }
}
