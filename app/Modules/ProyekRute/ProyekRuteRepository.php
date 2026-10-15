<?php

declare(strict_types=1);

namespace App\Modules\ProyekRute;

use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProyekRuteRepository implements ProyekRuteRepositoryInterface
{
    private function detailQuery(): Builder
    {
        return ProyekRuteModel::active()
            ->leftJoin('rute', 'rute.id_rute', '=', 'proyek_rute.id_rute')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'proyek_rute.id_jenis_kendaraan')
            ->select(
                'proyek_rute.*',
                'rute.kode_rute',
                'rute.nama_rute',
                'rute.asal',
                'rute.tujuan',
                'jenis_kendaraan.nama_jenis',
            );
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->exists();
    }

    public function listByProyek(string $idProyek): Collection
    {
        return $this->detailQuery()
            ->where('proyek_rute.id_proyek', $idProyek)
            ->orderBy('proyek_rute.dibuat_pada')
            ->get();
    }

    public function ruteTerdaftarUntukProyek(string $idProyek, string $idRute): bool
    {
        return ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->exists();
    }

    public function findById(string $id): ?ProyekRuteModel
    {
        return ProyekRuteModel::active()->find($id);
    }

    public function findDetailById(string $id): ?ProyekRuteModel
    {
        return $this->detailQuery()->where('proyek_rute.id_proyek_rute', $id)->first();
    }

    public function ruteMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('rute')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_rute', $id)
            ->first();
    }

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }

    public function existsDuplikat(string $idProyek, string $idRute, ?string $idJenisKendaraan, ?string $excludeId = null): bool
    {
        $query = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute);

        if ($idJenisKendaraan === null) {
            $query->whereNull('id_jenis_kendaraan');
        } else {
            $query->where('id_jenis_kendaraan', $idJenisKendaraan);
        }

        if ($excludeId !== null) {
            $query->where('id_proyek_rute', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Penugasan sengaja fleksibel — ops boleh assign armada jenis apa saja,
     * tidak wajib cocok dengan yang ada di penawaran/rate card. Resolusi tarif
     * 3 lapis: (1) cocok persis rute+jenis kendaraan, (2) baris "semua jenis"
     * (id_jenis_kendaraan NULL), (3) fallback tarif termurah di rute itu —
     * supaya trip tidak macet tak bisa ditagih hanya karena jenis armada beda,
     * nilainya tetap aman untuk klien (tidak pernah lebih mahal dari yang
     * disepakati di rute itu), dan masih bisa dikoreksi manual lewat Edit
     * Invoice sebelum draft dikirim. `tarif_perkiraan` ditandai true khusus
     * lapis fallback termurah, supaya pemanggil bisa kasih peringatan ke user.
     */
    public function findHarga(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?object
    {
        if ($idJenisKendaraan !== null) {
            $baris = ProyekRuteModel::active()
                ->where('id_proyek', $idProyek)
                ->where('id_rute', $idRute)
                ->where('id_jenis_kendaraan', $idJenisKendaraan)
                ->first();

            if ($baris !== null) {
                $baris->tarif_perkiraan = false;
                return $baris;
            }
        }

        $baris = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->whereNull('id_jenis_kendaraan')
            ->first();

        if ($baris !== null) {
            $baris->tarif_perkiraan = false;
            return $baris;
        }

        $baris = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->whereNotNull('harga_penawaran')
            ->orderBy('harga_penawaran')
            ->first();

        if ($baris !== null) {
            $baris->tarif_perkiraan = true;
        }

        return $baris;
    }

    public function adaPenawaranDisetujui(string $idProyek): bool
    {
        return DB::table('penawaran')
            ->where('id_proyek', $idProyek)
            ->where('status', 'disetujui')
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function tipeHargaProyek(string $idProyek): ?string
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->value('tipe_harga');
    }

    public function findBarisTepat(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?ProyekRuteModel
    {
        $query = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute);

        if ($idJenisKendaraan === null) {
            $query->whereNull('id_jenis_kendaraan');
        } else {
            $query->where('id_jenis_kendaraan', $idJenisKendaraan);
        }

        return $query->first();
    }

    public function tarifUangJalanRute(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?float
    {
        if ($idJenisKendaraan !== null) {
            $baris = ProyekRuteModel::active()
                ->where('id_proyek', $idProyek)
                ->where('id_rute', $idRute)
                ->where('id_jenis_kendaraan', $idJenisKendaraan)
                ->whereNotNull('uang_jalan')
                ->first();

            if ($baris !== null) {
                return (float) $baris->uang_jalan;
            }
        }

        $baris = ProyekRuteModel::active()
            ->where('id_proyek', $idProyek)
            ->where('id_rute', $idRute)
            ->whereNotNull('uang_jalan')
            ->orderBy('dibuat_pada')
            ->first();

        return $baris !== null ? (float) $baris->uang_jalan : null;
    }

    public function create(array $data): ProyekRuteModel
    {
        return ProyekRuteModel::create($data);
    }

    public function update(ProyekRuteModel $model, array $data): ProyekRuteModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function delete(ProyekRuteModel $model): void
    {
        $model->softDelete();
    }

    public function komponenUntuk(array $idProyekRute): array
    {
        if ($idProyekRute === []) {
            return [];
        }

        $peta = [];
        DB::table('proyek_rute_komponen as prk')
            ->join('komponen_uang_jalan as k', 'k.id_komponen_uang_jalan', '=', 'prk.id_komponen_uang_jalan')
            ->whereNull('prk.dihapus_pada')
            ->whereIn('prk.id_proyek_rute', $idProyekRute)
            ->orderBy('k.urutan')
            ->orderBy('k.nama')
            ->get(['prk.id_proyek_rute', 'prk.id_komponen_uang_jalan', 'k.nama', 'k.aktif', 'prk.nominal'])
            ->each(function ($r) use (&$peta) {
                $peta[(string) $r->id_proyek_rute][] = [
                    'id_komponen_uang_jalan' => (string) $r->id_komponen_uang_jalan,
                    'nama_komponen'          => (string) $r->nama,
                    'aktif'                  => (int) $r->aktif === 1,
                    'nominal'                => (float) $r->nominal,
                ];
            });

        return $peta;
    }

    public function idKomponenRute(string $idProyekRute): array
    {
        return DB::table('proyek_rute_komponen')
            ->whereNull('dihapus_pada')
            ->where('id_proyek_rute', $idProyekRute)
            ->pluck('id_komponen_uang_jalan')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function simpanKomponen(string $idProyekRute, string $idPerusahaan, array $rincian): void
    {
        DB::table('proyek_rute_komponen')
            ->whereNull('dihapus_pada')
            ->where('id_proyek_rute', $idProyekRute)
            ->update(RecordHelper::stampDelete());

        foreach ($rincian as $r) {
            DB::table('proyek_rute_komponen')->insert(RecordHelper::stampCreate([
                'id_perusahaan'          => $idPerusahaan,
                'id_proyek_rute'         => $idProyekRute,
                'id_komponen_uang_jalan' => $r['id_komponen_uang_jalan'],
                'nominal'                => $r['nominal'],
            ], 'id_proyek_rute_komponen'));
        }
    }
}
