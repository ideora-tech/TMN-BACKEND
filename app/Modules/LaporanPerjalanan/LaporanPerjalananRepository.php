<?php

declare(strict_types=1);

namespace App\Modules\LaporanPerjalanan;

use App\Modules\LaporanPerjalanan\Contracts\LaporanPerjalananRepositoryInterface;
use Illuminate\Support\Facades\DB;

class LaporanPerjalananRepository implements LaporanPerjalananRepositoryInterface
{
    public function findByTrip(string $idTrip): ?LaporanPerjalananModel
    {
        $laporan = LaporanPerjalananModel::active()
            ->where('id_trip', $idTrip)
            ->first();

        $this->attachDetail($laporan);

        return $laporan;
    }

    public function findById(string $id): ?LaporanPerjalananModel
    {
        $laporan = LaporanPerjalananModel::active()->find($id);
        $this->attachDetail($laporan);

        return $laporan;
    }

    public function findByIdMilik(string $id, string $idPerusahaan): ?LaporanPerjalananModel
    {
        $laporan = LaporanPerjalananModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->find($id);

        $this->attachDetail($laporan);

        return $laporan;
    }

    /**
     * Lampirkan pseudo-relasi biayaLain/biayaTagihan/foto via query builder biasa
     * (bukan Eloquent with()) — LaporanPerjalananResource masih memakai
     * whenLoaded('biayaLain') dkk, jadi cukup di-setRelation() tanpa mengubah
     * resource/kode pemanggil.
     */
    private function attachDetail(?LaporanPerjalananModel $laporan): void
    {
        if ($laporan === null) {
            return;
        }

        $laporan->setRelation('biayaLain', DB::table('biaya_lain_trip')
            ->where('id_laporan', $laporan->id_laporan)->whereNull('dihapus_pada')
            ->get(['id_biaya_lain', 'id_laporan', 'nama_biaya', 'nominal']));
        $laporan->setRelation('biayaTagihan', DB::table('biaya_tagihan_trip')
            ->where('id_laporan', $laporan->id_laporan)->whereNull('dihapus_pada')
            ->get(['id_biaya_tagihan', 'id_laporan', 'nama_biaya', 'nominal']));
        $laporan->setRelation('foto', DB::table('foto_laporan_perjalanan')
            ->where('id_laporan', $laporan->id_laporan)->whereNull('dihapus_pada')
            ->get(['id_foto', 'id_laporan', 'url_file', 'keterangan']));
    }

    public function create(array $data): LaporanPerjalananModel
    {
        return LaporanPerjalananModel::create($data);
    }

    public function update(LaporanPerjalananModel $model, array $data): LaporanPerjalananModel
    {
        $model->update($data);
        return $model;
    }

    public function reload(LaporanPerjalananModel $model): LaporanPerjalananModel
    {
        $fresh = $model->fresh();
        $this->attachDetail($fresh);

        return $fresh;
    }

    public function syncBiayaLain(LaporanPerjalananModel $laporan, array $biayaLain): void
    {
        BiayaLainTripModel::active()
            ->where('id_laporan', $laporan->id_laporan)
            ->each(fn (BiayaLainTripModel $item) => $item->softDelete());

        foreach ($biayaLain as $item) {
            BiayaLainTripModel::create([
                'id_laporan' => $laporan->id_laporan,
                'nama_biaya' => $item['nama_biaya'],
                'nominal'    => $item['nominal'],
            ]);
        }
    }

    public function syncBiayaTagihan(LaporanPerjalananModel $laporan, array $biayaTagihan): void
    {
        BiayaTagihanTripModel::active()
            ->where('id_laporan', $laporan->id_laporan)
            ->each(fn (BiayaTagihanTripModel $item) => $item->softDelete());

        foreach ($biayaTagihan as $item) {
            BiayaTagihanTripModel::create([
                'id_laporan' => $laporan->id_laporan,
                'nama_biaya' => $item['nama_biaya'],
                'nominal'    => $item['nominal'],
            ]);
        }
    }

    public function addFoto(string $idLaporan, array $data): FotoLaporanPerjalananModel
    {
        return FotoLaporanPerjalananModel::create(array_merge($data, ['id_laporan' => $idLaporan]));
    }

    public function findFotoById(string $idLaporan, string $idFoto): ?FotoLaporanPerjalananModel
    {
        return FotoLaporanPerjalananModel::active()
            ->where('id_laporan', $idLaporan)
            ->where('id_foto', $idFoto)
            ->first();
    }

    public function deleteFoto(FotoLaporanPerjalananModel $foto): void
    {
        $foto->softDelete();
    }

    public function tripMilikPerusahaan(string $idTrip, string $idPerusahaan): bool
    {
        return DB::table('trip')
            ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
            ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
            ->where('trip.id_trip', $idTrip)
            ->where('pr.id_perusahaan', $idPerusahaan)
            ->whereNull('trip.dihapus_pada')
            ->whereNull('jk.dihapus_pada')
            ->whereNull('p.dihapus_pada')
            ->whereNull('pr.dihapus_pada')
            ->exists();
    }

    public function mekanismeKontrak(string $idKontrakVendor): ?string
    {
        $mekanisme = DB::table('kontrak_vendor')
            ->whereNull('dihapus_pada')
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->value('mekanisme');

        return $mekanisme !== null ? (string) $mekanisme : null;
    }
}
