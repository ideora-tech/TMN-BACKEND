<?php

declare(strict_types=1);

namespace App\Modules\ParameterTagihanTrip;

use App\Modules\ParameterTagihanTrip\Contracts\ParameterTagihanTripRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class ParameterTagihanTripRepository implements ParameterTagihanTripRepositoryInterface
{
    public function konteksTrip(string $idTrip, string $idPerusahaan): ?object
    {
        return DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
            ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
            ->where('t.id_trip', $idTrip)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('t.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->first([
                't.id_trip',
                't.status',
                'jk.id_rute',
                'pr.id_proyek',
                'pr.tipe_harga',
                'a.id_jenis_kendaraan',
                'av.id_jenis_kendaraan as id_jenis_kendaraan_vendor',
            ]);
    }

    public function kunciTrip(string $idTrip): void
    {
        DB::table('trip')->where('id_trip', $idTrip)->lockForUpdate()->value('id_trip');
    }

    public function tripPunyaFakturAktif(string $idTrip): bool
    {
        return DB::table('faktur_trip as ft')
            ->join('faktur as f', 'f.id_faktur', '=', 'ft.id_faktur')
            ->where('ft.id_trip', $idTrip)
            ->whereNull('ft.dihapus_pada')
            ->whereNull('f.dihapus_pada')
            ->where('f.status', '!=', 'batal')
            ->exists();
    }

    public function findByTrip(string $idTrip): ?object
    {
        return DB::table('parameter_tagihan_trip')
            ->where('id_trip', $idTrip)
            ->whereNull('dihapus_pada')
            ->first();
    }

    public function simpan(string $idTrip, array $data): void
    {
        $ada = DB::table('parameter_tagihan_trip')->where('id_trip', $idTrip)->exists();

        if ($ada) {
            DB::table('parameter_tagihan_trip')
                ->where('id_trip', $idTrip)
                ->update(RecordHelper::stampUpdate($data + ['dihapus_pada' => null, 'dihapus_oleh' => null]));
            return;
        }

        DB::table('parameter_tagihan_trip')->insert(
            RecordHelper::stampCreate($data + ['id_trip' => $idTrip], 'id_parameter_tagihan')
        );
    }

    public function namaPengguna(?string $idPengguna): ?string
    {
        if ($idPengguna === null) {
            return null;
        }

        return DB::table('pengguna')->where('id_pengguna', $idPengguna)->value('username');
    }
}
