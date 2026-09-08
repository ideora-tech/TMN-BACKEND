<?php

declare(strict_types=1);

namespace App\Modules\KontrakVendor;

use App\Modules\KontrakVendor\Contracts\KontrakVendorRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KontrakVendorRepository implements KontrakVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idVendor = null, ?string $search = null): LengthAwarePaginator
    {
        $paginator = KontrakVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($idVendor, fn ($q) => $q->where('id_vendor', $idVendor))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('mekanisme', 'like', "%{$search}%")
                   ->orWhereIn('id_vendor', function ($sub) use ($search) {
                       $sub->select('id_vendor')
                           ->from('vendor')
                           ->whereNull('dihapus_pada')
                           ->where('nama_vendor', 'like', "%{$search}%");
                   });
            }))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachNamaVendor($paginator->getCollection());
        $this->attachInfoTurunan($paginator->getCollection());

        return $paginator;
    }

    public function paginateByProyek(string $idPerusahaan, string $idProyek, int $page, int $limit): LengthAwarePaginator
    {
        $paginator = KontrakVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_proyek', $idProyek)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachNamaVendor($paginator->getCollection());
        $this->attachInfoTurunan($paginator->getCollection());

        return $paginator;
    }

    public function findById(string $id): ?KontrakVendorModel
    {
        $record = KontrakVendorModel::active()->find($id);
        if ($record !== null) {
            $this->attachNamaVendor(collect([$record]));
        }
        return $record;
    }

    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?KontrakVendorModel
    {
        $record = KontrakVendorModel::active()
            ->where('id_kontrak_vendor', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();

        if ($record !== null) {
            $this->attachNamaVendor(collect([$record]));
            $this->attachInfoTurunan(collect([$record]));
            $this->attachTurunan($record);
        }
        return $record;
    }

    /**
     * Tempel nama_vendor ke tiap record via raw query builder (join manual),
     * bukan Eloquent relationship — hindari overhead & N+1 tersembunyi ala ORM.
     */
    private function attachNamaVendor(Collection $records): void
    {
        $idVendorList = $records->pluck('id_vendor')->filter()->unique()->values()->all();
        if (empty($idVendorList)) {
            return;
        }

        $namaByIdVendor = DB::table('vendor')
            ->whereIn('id_vendor', $idVendorList)
            ->pluck('nama_vendor', 'id_vendor');

        foreach ($records as $record) {
            $record->vendor_nama = $namaByIdVendor[$record->id_vendor] ?? null;
            // Atribut tempelan bukan kolom tabel — sinkronkan ke original supaya
            // tidak dianggap dirty dan ikut tersimpan saat update()/softDelete().
            $record->syncOriginalAttribute('vendor_nama');
        }
    }

    private function attachInfoTurunan(Collection $records): void
    {
        $idKontrakList = $records->pluck('id_kontrak_vendor')->filter()->unique()->values()->all();
        $idIndukList = $records->pluck('id_kontrak_induk')->filter()->unique()->values()->all();

        $nomorByIdInduk = !empty($idIndukList)
            ? DB::table('kontrak_vendor')
                ->whereIn('id_kontrak_vendor', $idIndukList)
                ->whereNull('dihapus_pada')
                ->pluck('nomor_kontrak', 'id_kontrak_vendor')
            : collect();

        $jumlahByIdInduk = !empty($idKontrakList)
            ? DB::table('kontrak_vendor')
                ->select('id_kontrak_induk', DB::raw('count(*) as jumlah'))
                ->whereIn('id_kontrak_induk', $idKontrakList)
                ->whereNull('dihapus_pada')
                ->groupBy('id_kontrak_induk')
                ->pluck('jumlah', 'id_kontrak_induk')
            : collect();

        $permintaanByKontrak = !empty($idKontrakList)
            ? DB::table('permintaan_vendor')
                ->whereIn('id_kontrak_vendor', $idKontrakList)
                ->whereNull('dihapus_pada')
                ->get(['id_kontrak_vendor', 'id_permintaan', 'nomor_permintaan'])
                ->keyBy('id_kontrak_vendor')
            : collect();

        $idProyekList = $records->pluck('id_proyek')->filter()->unique()->values()->all();
        $namaProyekById = !empty($idProyekList)
            ? DB::table('proyek')
                ->whereIn('id_proyek', $idProyekList)
                ->whereNull('dihapus_pada')
                ->pluck('nama_proyek', 'id_proyek')
            : collect();

        foreach ($records as $record) {
            $record->nomor_kontrak_induk = $record->id_kontrak_induk !== null
                ? ($nomorByIdInduk[$record->id_kontrak_induk] ?? null)
                : null;
            $record->jumlah_turunan = (int) ($jumlahByIdInduk[$record->id_kontrak_vendor] ?? 0);
            $asalPermintaan = $permintaanByKontrak[$record->id_kontrak_vendor] ?? null;
            $record->id_permintaan = $asalPermintaan->id_permintaan ?? null;
            $record->nomor_permintaan = $asalPermintaan->nomor_permintaan ?? null;
            $record->nama_proyek = $record->id_proyek !== null
                ? ($namaProyekById[$record->id_proyek] ?? null)
                : null;
            $record->syncOriginalAttribute('nomor_kontrak_induk');
            $record->syncOriginalAttribute('jumlah_turunan');
            $record->syncOriginalAttribute('id_permintaan');
            $record->syncOriginalAttribute('nomor_permintaan');
            $record->syncOriginalAttribute('nama_proyek');
        }
    }

    private function attachTurunan(KontrakVendorModel $record): void
    {
        $anak = KontrakVendorModel::active()
            ->where('id_kontrak_induk', $record->id_kontrak_vendor)
            ->get(['id_kontrak_vendor', 'nomor_kontrak', 'mekanisme', 'tanggal_mulai', 'tanggal_selesai', 'nilai_kontrak', 'status']);

        $record->turunan = $anak->map(static fn ($a) => [
            'id_kontrak_vendor' => $a->id_kontrak_vendor,
            'nomor_kontrak'     => $a->nomor_kontrak,
            'mekanisme'         => $a->mekanisme,
            'tanggal_mulai'     => $a->tanggal_mulai,
            'tanggal_selesai'   => $a->tanggal_selesai,
            'nilai_kontrak'     => (float) $a->nilai_kontrak,
            'status'            => $a->status,
        ])->values()->all();

        $record->total_nilai_turunan = (float) $anak->sum('nilai_kontrak');
        $record->syncOriginalAttribute('turunan');
        $record->syncOriginalAttribute('total_nilai_turunan');
    }

    public function jumlahTurunan(string $idKontrakVendor): int
    {
        return KontrakVendorModel::active()
            ->where('id_kontrak_induk', $idKontrakVendor)
            ->count();
    }

    public function create(array $data): KontrakVendorModel
    {
        return KontrakVendorModel::create($data);
    }

    public function vendorMilikPerusahaan(string $idVendor, string $idPerusahaan): bool
    {
        return DB::table('vendor')
            ->where('id_vendor', $idVendor)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function relinkUnitDanSupir(string $idKontrakLama, string $idKontrakBaru): void
    {
        foreach (['armada_vendor', 'supir_vendor'] as $tabel) {
            DB::table($tabel)
                ->whereNull('dihapus_pada')
                ->where('id_kontrak_vendor', $idKontrakLama)
                ->update([
                    'id_kontrak_vendor' => $idKontrakBaru,
                    'diubah_pada'       => now(),
                ]);
        }
    }

    public function update(KontrakVendorModel $model, array $data): KontrakVendorModel
    {
        $model->update($data);
        $fresh = $model->fresh();
        $this->attachNamaVendor(collect([$fresh]));
        $this->attachInfoTurunan(collect([$fresh]));
        $this->attachTurunan($fresh);
        return $fresh;
    }

    public function delete(KontrakVendorModel $model): void
    {
        $model->softDelete();
    }

    public function turunkanKeDraftJikaPerluApprovalUlang(string $idKontrak): ?string
    {
        $kontrak = $this->findById($idKontrak);
        if ($kontrak === null || !in_array($kontrak->status, ['aktif', 'menunggu_approval'], true)) {
            return null;
        }
        $statusSebelum = $kontrak->status;
        $this->update($kontrak, ['status' => 'draft', 'alasan_ditolak_internal' => null]);
        return $statusSebelum;
    }

    public function getNamaVendor(string $idVendor): ?string
    {
        return DB::table('vendor')->where('id_vendor', $idVendor)->value('nama_vendor');
    }

    public function getPerusahaan(string $idPerusahaan): ?object
    {
        return DB::table('perusahaan')->where('id_perusahaan', $idPerusahaan)->first();
    }

    public function adaPenugasanNonFinalUntukArmadaVendor(string $idArmadaVendor): bool
    {
        return \Illuminate\Support\Facades\DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_armada_vendor', $idArmadaVendor)
            ->whereNotIn('status', ['selesai', 'batal'])
            ->exists();
    }

    public function adaPenugasanNonFinalUntukSupirVendor(string $idSupirVendor): bool
    {
        return \Illuminate\Support\Facades\DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_supir_vendor', $idSupirVendor)
            ->whereNotIn('status', ['selesai', 'batal'])
            ->exists();
    }

    /**
     * Penugasan hidup apa pun mengunci kontrak dari penghapusan. Penugasan yang
     * sudah dihapus hanya ikut mengunci bila sempat menghasilkan trip — trip
     * historis tetap butuh kontraknya untuk penagihan/audit; penugasan coba-coba
     * yang dihapus tanpa pernah jalan tidak perlu memblokir.
     */
    public function adaPenugasanUntukKontrak(string $idKontrakVendor): bool
    {
        $adaHidup = DB::table('penugasan')
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->whereNull('dihapus_pada')
            ->exists();
        if ($adaHidup) {
            return true;
        }

        return DB::table('penugasan as p')
            ->join('jadwal_keberangkatan as jk', 'jk.id_penugasan', '=', 'p.id_penugasan')
            ->join('trip as t', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->where('p.id_kontrak_vendor', $idKontrakVendor)
            ->whereNotNull('p.dihapus_pada')
            ->exists();
    }

    public function lepasTautanUnitDanSupir(string $idKontrakVendor): void
    {
        foreach (['armada_vendor', 'supir_vendor'] as $tabel) {
            DB::table($tabel)
                ->whereNull('dihapus_pada')
                ->where('id_kontrak_vendor', $idKontrakVendor)
                ->update([
                    'id_kontrak_vendor' => null,
                    'diubah_pada'       => now(),
                ]);
        }
    }
}
