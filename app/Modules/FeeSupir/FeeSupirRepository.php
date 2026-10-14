<?php

declare(strict_types=1);

namespace App\Modules\FeeSupir;

use App\Modules\FeeSupir\Contracts\FeeSupirRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FeeSupirRepository implements FeeSupirRepositoryInterface
{
    public function infoTrip(string $idTrip): ?object
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as j', 'j.id_jadwal', '=', 't.id_jadwal')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'j.id_penugasan')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->whereNull('t.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->where('t.id_trip', $idTrip)
            ->first([
                't.id_trip', 't.status', 't.waktu_checkout',
                'p.id_penugasan', 'p.id_proyek', 'p.id_rute', 'p.id_supir', 'p.fee_supir', 'p.tanggal_tugas',
                'pr.id_perusahaan', 'pr.tipe_harga',
            ]);
    }

    public function idTripSelesaiPenugasan(string $idPenugasan): array
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as j', 'j.id_jadwal', '=', 't.id_jadwal')
            ->whereNull('t.dihapus_pada')
            ->where('j.id_penugasan', $idPenugasan)
            ->where('t.status', 'selesai')
            ->pluck('t.id_trip')
            ->all();
    }

    public function jumlahPenugasanSupirSebelum(string $idSupir, string $idProyek, string $tanggal, ?string $idPenugasan): int
    {
        $query = DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('status', '!=', 'batal')
            ->where('id_supir', $idSupir)
            ->where('id_proyek', $idProyek)
            ->where('tanggal_tugas', $tanggal);

        if ($idPenugasan !== null) {
            $acuan = DB::table('penugasan')->where('id_penugasan', $idPenugasan)->first(['id_penugasan', 'dibuat_pada']);
            $query->where('id_penugasan', '!=', $idPenugasan);
            if ($acuan !== null && $acuan->dibuat_pada !== null) {
                $query->where(function ($q) use ($acuan) {
                    $q->where('dibuat_pada', '<', $acuan->dibuat_pada)
                        ->orWhere(function ($q2) use ($acuan) {
                            $q2->where('dibuat_pada', $acuan->dibuat_pada)->where('id_penugasan', '<', $acuan->id_penugasan);
                        });
                });
            }
        }

        return $query->count();
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): ?object
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->first(['id_proyek', 'tipe_harga']);
    }

    public function findByTrip(string $idTrip): ?object
    {
        return DB::table('fee_supir_trip')
            ->whereNull('dihapus_pada')
            ->where('id_trip', $idTrip)
            ->first();
    }

    public function findTerhapusByTrip(string $idTrip): ?object
    {
        return DB::table('fee_supir_trip')
            ->whereNotNull('dihapus_pada')
            ->where('id_trip', $idTrip)
            ->first();
    }

    public function create(array $data): void
    {
        DB::table('fee_supir_trip')->insert(RecordHelper::stampCreate($data, 'id_fee_supir_trip'));
    }

    public function perbarui(string $idFee, array $data): void
    {
        DB::table('fee_supir_trip')
            ->where('id_fee_supir_trip', $idFee)
            ->update(RecordHelper::stampUpdate($data));
    }

    public function pulihkan(string $idFee, array $data): void
    {
        DB::table('fee_supir_trip')
            ->where('id_fee_supir_trip', $idFee)
            ->update(RecordHelper::stampUpdate(array_merge($data, ['dihapus_pada' => null, 'dihapus_oleh' => null])));
    }

    public function hapusUntukTrip(string $idTrip): void
    {
        DB::table('fee_supir_trip')
            ->whereNull('dihapus_pada')
            ->where('id_trip', $idTrip)
            ->update(RecordHelper::stampDelete());
    }

    public function daftarUntukSupir(string $idSupir, string $dari, string $sampai): array
    {
        return DB::table('fee_supir_trip as f')
            ->join('trip as t', 't.id_trip', '=', 'f.id_trip')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'f.id_penugasan')
            ->leftJoin('proyek as pr', 'pr.id_proyek', '=', 'f.id_proyek')
            ->leftJoin('rute as r', 'r.id_rute', '=', 'f.id_rute')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->whereNull('f.dihapus_pada')
            ->whereNull('t.dihapus_pada')
            ->where('f.id_supir', $idSupir)
            ->where('f.nominal', '>', 0)
            ->whereBetween('f.tanggal', [$dari, $sampai])
            ->orderByDesc('f.tanggal')
            ->orderByDesc('t.waktu_checkout')
            ->orderBy('f.id_fee_supir_trip')
            ->get([
                'f.id_fee_supir_trip', 'f.id_trip', 'f.tanggal', 'f.nominal', 't.waktu_checkout',
                'pr.kode_proyek', 'pr.nama_proyek', 'r.nama_rute', 'r.asal', 'r.tujuan',
                DB::raw('COALESCE(a.nopol, av.nopol) as nopol'),
            ])
            ->all();
    }

    public function ringkasan(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): object
    {
        return $this->tersaring($idPerusahaan, $dari, $sampai, $search, $idSupir)
            ->selectRaw('COUNT(*) as jumlah_trip, COALESCE(SUM(f.nominal), 0) as total, COUNT(DISTINCT f.id_supir) as jumlah_supir')
            ->first();
    }

    public function paginateRekap(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir, int $page, int $limit): LengthAwarePaginator
    {
        return $this->rekap($idPerusahaan, $dari, $sampai, $search, $idSupir)->paginate($limit, ['*'], 'page', $page);
    }

    public function semuaRekap(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): array
    {
        return $this->rekap($idPerusahaan, $dari, $sampai, $search, $idSupir)->get()->all();
    }

    public function paginateRincian(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir, int $page, int $limit): LengthAwarePaginator
    {
        return $this->rincian($idPerusahaan, $dari, $sampai, $search, $idSupir)->paginate($limit, ['*'], 'page', $page);
    }

    public function semuaRincian(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir, int $batas): array
    {
        return $this->rincian($idPerusahaan, $dari, $sampai, $search, $idSupir)->limit($batas)->get()->all();
    }

    private function rekap(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): Builder
    {
        return $this->tersaring($idPerusahaan, $dari, $sampai, $search, $idSupir)
            ->groupBy('f.id_supir', 's.nama', 's.telepon')
            ->select([
                'f.id_supir', 's.nama as nama_supir', 's.telepon',
                DB::raw('COUNT(*) as jumlah_trip'),
                DB::raw('SUM(f.nominal) as total'),
                DB::raw('MAX(f.tanggal) as tanggal_terakhir'),
            ])
            ->orderByDesc('total')
            ->orderBy('s.nama')
            ->orderBy('f.id_supir');
    }

    private function rincian(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): Builder
    {
        return $this->tersaring($idPerusahaan, $dari, $sampai, $search, $idSupir)
            ->select([
                'f.id_fee_supir_trip', 'f.id_trip', 'f.id_supir', 'f.tanggal', 'f.nominal', 't.waktu_checkout',
                's.nama as nama_supir', 'pr.kode_proyek', 'pr.nama_proyek', 'r.nama_rute', 'r.asal', 'r.tujuan',
                DB::raw('COALESCE(a.nopol, av.nopol) as nopol'),
            ])
            ->orderByDesc('f.tanggal')
            ->orderByDesc('t.waktu_checkout')
            ->orderBy('f.id_fee_supir_trip');
    }

    private function tersaring(string $idPerusahaan, string $dari, string $sampai, ?string $search, ?string $idSupir): Builder
    {
        $query = DB::table('fee_supir_trip as f')
            ->join('trip as t', 't.id_trip', '=', 'f.id_trip')
            ->join('penugasan as p', 'p.id_penugasan', '=', 'f.id_penugasan')
            ->leftJoin('supir as s', 's.id_supir', '=', 'f.id_supir')
            ->leftJoin('proyek as pr', 'pr.id_proyek', '=', 'f.id_proyek')
            ->leftJoin('rute as r', 'r.id_rute', '=', 'f.id_rute')
            ->leftJoin('armada as a', 'a.id_armada', '=', 'p.id_armada')
            ->leftJoin('armada_vendor as av', 'av.id_armada_vendor', '=', 'p.id_armada_vendor')
            ->whereNull('f.dihapus_pada')
            ->whereNull('t.dihapus_pada')
            ->where('f.id_perusahaan', $idPerusahaan)
            ->where('f.nominal', '>', 0)
            ->whereBetween('f.tanggal', [$dari, $sampai]);

        if ($idSupir !== null) {
            $query->where('f.id_supir', $idSupir);
        }

        if ($search !== null) {
            $query->where(function ($q) use ($search) {
                $q->where('s.nama', 'like', "%{$search}%")
                    ->orWhere('pr.nama_proyek', 'like', "%{$search}%")
                    ->orWhere('pr.kode_proyek', 'like', "%{$search}%")
                    ->orWhere('r.nama_rute', 'like', "%{$search}%")
                    ->orWhere('a.nopol', 'like', "%{$search}%")
                    ->orWhere('av.nopol', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
