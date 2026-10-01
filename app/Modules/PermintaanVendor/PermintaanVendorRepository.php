<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor;

use App\Modules\PermintaanVendor\Contracts\PermintaanVendorRepositoryInterface;
use App\Support\RecordHelper;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PermintaanVendorRepository implements PermintaanVendorRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search = null, ?string $status = null, ?string $idPenawaran = null): LengthAwarePaginator
    {
        $paginator = PermintaanVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->when($idPenawaran, fn ($q, $v) => $q->where('id_penawaran', $v))
            ->when($this->daftarStatus($status), fn ($q, array $daftar) => $q->whereIn('status', $daftar))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nomor_permintaan', 'like', "%{$search}%")
                   ->orWhereIn('id_proyek', function ($sub) use ($search) {
                       $sub->select('id_proyek')
                           ->from('proyek')
                           ->whereNull('dihapus_pada')
                           ->where('nama_proyek', 'like', "%{$search}%");
                   });
            }))
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        $this->attachReferensi($paginator->getCollection());

        return $paginator;
    }

    private function daftarStatus(?string $status): array
    {
        if ($status === null || trim($status) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $status)), static fn ($s) => $s !== ''));
    }

    public function ringkasanStatus(string $idPerusahaan): array
    {
        return PermintaanVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->select('status', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('status')
            ->pluck('jumlah', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function ringkasanKpi(string $idPerusahaan): array
    {
        $menit = PermintaanVendorModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNotNull('disetujui_pada')
            ->whereNotNull('dikontrakkan_pada')
            ->get(['disetujui_pada', 'dikontrakkan_pada'])
            ->map(fn ($r) => max(0, (int) round(Carbon::parse($r->disetujui_pada)->diffInMinutes(Carbon::parse($r->dikontrakkan_pada)))))
            ->all();

        return [
            'jumlah_terpenuhi' => count($menit),
            'rata_rata_menit'  => $menit === [] ? null : (int) round(array_sum($menit) / count($menit)),
        ];
    }

    public function listMenungguDiproses(string $idPerusahaan, int $limit): array
    {
        return DB::table('permintaan_vendor as pv')
            ->leftJoin('proyek as pr', function ($j) {
                $j->on('pr.id_proyek', '=', 'pv.id_proyek')->whereNull('pr.dihapus_pada');
            })
            ->whereNull('pv.dihapus_pada')
            ->where('pv.id_perusahaan', $idPerusahaan)
            ->whereIn('pv.status', ['disetujui', 'diproses'])
            ->orderBy('pv.dibuat_pada')
            ->limit($limit)
            ->get([
                'pv.id_permintaan', 'pv.nomor_permintaan', 'pr.nama_proyek', 'pv.status', 'pv.mekanisme',
                'pv.jumlah_unit', 'pv.periode_dari', 'pv.periode_sampai', 'pv.dibuat_pada',
            ])
            ->all();
    }

    public function selesaikanOlehKontrak(string $idKontrakVendor): array
    {
        $records = PermintaanVendorModel::active()
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->where('status', 'dikontrakkan')
            ->lockForUpdate()
            ->get();

        $hasil = [];
        foreach ($records as $record) {
            $hasil[] = $this->update($record, ['status' => 'selesai']);
        }
        return $hasil;
    }

    public function nomorPermintaanTerpenuhiOlehKontrak(string $idKontrakVendor): ?string
    {
        return PermintaanVendorModel::active()
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->where('status', '!=', 'dikontrakkan')
            ->value('nomor_permintaan');
    }

    public function kembalikanDariKontrak(string $idKontrakVendor): array
    {
        $records = PermintaanVendorModel::active()
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->where('status', 'dikontrakkan')
            ->lockForUpdate()
            ->get();

        $hasil = [];
        foreach ($records as $record) {
            $hasil[] = $this->update($record, [
                'status'            => $record->diproses_oleh !== null ? 'diproses' : 'disetujui',
                'id_kontrak_vendor' => null,
                'dikontrakkan_pada' => null,
            ]);
        }
        return $hasil;
    }

    public function findAktifMilikPerusahaan(string $id, string $idPerusahaan): ?PermintaanVendorModel
    {
        $record = PermintaanVendorModel::active()
            ->where('id_permintaan', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();

        if ($record !== null) {
            $this->attachReferensi(collect([$record]));
        }
        return $record;
    }

    public function findForUpdate(string $id): ?PermintaanVendorModel
    {
        return PermintaanVendorModel::active()
            ->where('id_permintaan', $id)
            ->lockForUpdate()
            ->first();
    }

    private function attachReferensi(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $namaProyek = DB::table('proyek')
            ->whereIn('id_proyek', $records->pluck('id_proyek')->filter()->unique()->values()->all())
            ->pluck('nama_proyek', 'id_proyek');
        $nomorPenawaran = DB::table('penawaran')
            ->whereIn('id_penawaran', $records->pluck('id_penawaran')->filter()->unique()->values()->all())
            ->pluck('nomor_penawaran', 'id_penawaran');
        $namaJenis = DB::table('jenis_kendaraan')
            ->whereIn('id_jenis_kendaraan', $records->pluck('id_jenis_kendaraan')->filter()->unique()->values()->all())
            ->pluck('nama_jenis', 'id_jenis_kendaraan');
        $nomorKontrak = DB::table('kontrak_vendor')
            ->whereIn('id_kontrak_vendor', $records->pluck('id_kontrak_vendor')->filter()->unique()->values()->all())
            ->pluck('nomor_kontrak', 'id_kontrak_vendor');
        $namaPemroses = DB::table('pengguna')
            ->whereIn('id_pengguna', $records->pluck('diproses_oleh')->filter()->unique()->values()->all())
            ->pluck('username', 'id_pengguna');
        $namaPenolak = DB::table('pengguna')
            ->whereIn('id_pengguna', $records->pluck('ditolak_pengadaan_oleh')->filter()->unique()->values()->all())
            ->pluck('username', 'id_pengguna');
        $unitPerPermintaan = $this->unitUntukBanyak($records->pluck('id_permintaan')->unique()->values()->all());

        foreach ($records as $record) {
            $record->nama_proyek = $record->id_proyek !== null ? ($namaProyek[$record->id_proyek] ?? null) : null;
            $record->syncOriginalAttribute('nama_proyek');
            $record->nomor_penawaran = $record->id_penawaran !== null ? ($nomorPenawaran[$record->id_penawaran] ?? null) : null;
            $record->syncOriginalAttribute('nomor_penawaran');
            $record->nama_jenis_kendaraan = $record->id_jenis_kendaraan !== null ? ($namaJenis[$record->id_jenis_kendaraan] ?? null) : null;
            $record->syncOriginalAttribute('nama_jenis_kendaraan');
            $record->nomor_kontrak = $record->id_kontrak_vendor !== null ? ($nomorKontrak[$record->id_kontrak_vendor] ?? null) : null;
            $record->syncOriginalAttribute('nomor_kontrak');
            $record->unit_diminta = $unitPerPermintaan[$record->id_permintaan] ?? [];
            $record->syncOriginalAttribute('unit_diminta');
            $record->nama_ditolak_pengadaan_oleh = $record->ditolak_pengadaan_oleh !== null ? ($namaPenolak[$record->ditolak_pengadaan_oleh] ?? null) : null;
            $record->syncOriginalAttribute('nama_ditolak_pengadaan_oleh');
            $record->nama_diproses_oleh = $record->diproses_oleh !== null ? ($namaPemroses[$record->diproses_oleh] ?? null) : null;
            $record->syncOriginalAttribute('nama_diproses_oleh');
        }
    }

    public function create(array $data, array $unitRows = []): PermintaanVendorModel
    {
        $record = PermintaanVendorModel::create($data);
        $this->replaceUnit((string) $record->id_permintaan, $unitRows);
        $fresh = $record->fresh();
        $this->attachReferensi(collect([$fresh]));
        return $fresh;
    }

    public function update(PermintaanVendorModel $model, array $data, ?array $unitRows = null): PermintaanVendorModel
    {
        $model->update($data);
        if ($unitRows !== null) {
            $this->replaceUnit((string) $model->id_permintaan, $unitRows);
        }
        $fresh = $model->fresh();
        $this->attachReferensi(collect([$fresh]));
        return $fresh;
    }

    public function unitUntukBanyak(array $idPermintaanList): array
    {
        if ($idPermintaanList === []) {
            return [];
        }

        $rows = DB::table('permintaan_vendor_unit')
            ->whereIn('id_permintaan', $idPermintaanList)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->get(['id_permintaan', 'id_jenis_kendaraan', 'jumlah_unit']);

        $namaJenis = DB::table('jenis_kendaraan')
            ->whereIn('id_jenis_kendaraan', $rows->pluck('id_jenis_kendaraan')->filter()->unique()->values()->all())
            ->pluck('nama_jenis', 'id_jenis_kendaraan');

        return $rows
            ->groupBy('id_permintaan')
            ->map(fn ($grup) => $grup->map(fn ($item) => [
                'id_jenis_kendaraan'   => $item->id_jenis_kendaraan,
                'nama_jenis_kendaraan' => $item->id_jenis_kendaraan !== null ? ($namaJenis[$item->id_jenis_kendaraan] ?? null) : null,
                'jumlah_unit'          => (int) $item->jumlah_unit,
            ])->values()->all())
            ->all();
    }

    public function replaceUnit(string $idPermintaan, array $unitRows): void
    {
        DB::table('permintaan_vendor_unit')
            ->where('id_permintaan', $idPermintaan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        foreach (array_values($unitRows) as $i => $row) {
            DB::table('permintaan_vendor_unit')->insert(RecordHelper::stampCreate([
                'id_permintaan'      => $idPermintaan,
                'id_jenis_kendaraan' => $row['id_jenis_kendaraan'] ?? null,
                'jumlah_unit'        => (int) $row['jumlah_unit'],
                'urutan'             => $i + 1,
            ], 'id_permintaan_unit'));
        }
    }

    public function delete(PermintaanVendorModel $model): void
    {
        $model->softDelete();
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function penawaranMilikPerusahaan(string $idPenawaran, string $idPerusahaan): ?object
    {
        return DB::table('penawaran')
            ->where('id_penawaran', $idPenawaran)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->first(['id_penawaran', 'id_proyek', 'status']);
    }

    public function tautkanProyekDariPenawaran(string $idPenawaran, string $idProyek): void
    {
        $idKontrakList = PermintaanVendorModel::active()
            ->where('id_penawaran', $idPenawaran)
            ->whereNull('id_proyek')
            ->pluck('id_kontrak_vendor')
            ->filter()
            ->unique()
            ->values()
            ->all();

        DB::table('permintaan_vendor')
            ->where('id_penawaran', $idPenawaran)
            ->whereNull('id_proyek')
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampUpdate(['id_proyek' => $idProyek]));

        if ($idKontrakList !== []) {
            DB::table('kontrak_vendor')
                ->whereIn('id_kontrak_vendor', $idKontrakList)
                ->whereNull('id_proyek')
                ->whereNull('dihapus_pada')
                ->update(RecordHelper::stampUpdate(['id_proyek' => $idProyek]));
        }
    }

    public function jenisKendaraanMilikPerusahaan(string $idJenisKendaraan, string $idPerusahaan): bool
    {
        return DB::table('jenis_kendaraan')
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }
}
