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

    public function adaPenugasanUntukKontrak(string $idKontrakVendor): bool
    {
        return DB::table('penugasan')
            ->where('id_kontrak_vendor', $idKontrakVendor)
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
