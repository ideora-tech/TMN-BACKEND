<?php

declare(strict_types=1);

namespace App\Modules\DokumenKaryawan;

use App\Modules\DokumenKaryawan\Contracts\DokumenKaryawanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DokumenKaryawanRepository implements DokumenKaryawanRepositoryInterface
{
    private const COLUMNS = [
        'dokumen_karyawan.id_dokumen_karyawan', 'dokumen_karyawan.id_karyawan', 'dokumen_karyawan.id_supir', 'dokumen_karyawan.jenis_dokumen',
        'dokumen_karyawan.nomor', 'dokumen_karyawan.berlaku_sampai', 'dokumen_karyawan.url_file',
        'dokumen_karyawan.dibuat_pada', 'dokumen_karyawan.dibuat_oleh',
        'dokumen_karyawan.diubah_pada', 'dokumen_karyawan.diubah_oleh',
        'dokumen_karyawan.dihapus_pada', 'dokumen_karyawan.dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idKaryawan = null, ?string $jenisDokumen = null, ?string $search = null): LengthAwarePaginator
    {
        return DB::table('dokumen_karyawan')
            ->join('karyawan', 'karyawan.id_karyawan', '=', 'dokumen_karyawan.id_karyawan')
            ->where('karyawan.id_perusahaan', $idPerusahaan)
            ->whereNull('dokumen_karyawan.dihapus_pada')
            ->whereNull('karyawan.dihapus_pada')
            ->when($idKaryawan, fn ($q, $v) => $q->where('dokumen_karyawan.id_karyawan', $v))
            ->when($jenisDokumen, fn ($q, $v) => $q->where('dokumen_karyawan.jenis_dokumen', $v))
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('dokumen_karyawan.nomor', 'like', "%{$search}%")
                   ->orWhere('karyawan.nama_karyawan', 'like', "%{$search}%")
                   ->orWhere('karyawan.nik', 'like', "%{$search}%");
            }))
            ->orderByRaw('dokumen_karyawan.berlaku_sampai is null')
            ->orderBy('dokumen_karyawan.berlaku_sampai')
            ->select(array_merge(self::COLUMNS, ['karyawan.nama_karyawan as karyawan_nama', 'karyawan.nik as karyawan_nik']))
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function findAllByKaryawan(string $idKaryawan): array
    {
        return DB::table('dokumen_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $idKaryawan)
            ->orderBy('jenis_dokumen')
            ->orderByDesc('dibuat_pada')
            ->get()
            ->all();
    }

    public function findAllBySupir(string $idSupir, ?string $idKaryawan): array
    {
        return DB::table('dokumen_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where(function ($q) use ($idSupir, $idKaryawan) {
                $q->where(fn ($q2) => $q2->whereNull('id_karyawan')->where('id_supir', $idSupir));
                if ($idKaryawan !== null) {
                    $q->orWhere('id_karyawan', $idKaryawan);
                }
            })
            ->orderBy('jenis_dokumen')
            ->orderByDesc('dibuat_pada')
            ->get()
            ->all();
    }

    public function maxBerlakuSimTanpaKaryawan(string $idSupir): ?string
    {
        $nilai = DB::table('dokumen_karyawan')
            ->whereNull('id_karyawan')
            ->where('id_supir', $idSupir)
            ->whereRaw("UPPER(jenis_dokumen) = 'SIM'")
            ->whereNull('dihapus_pada')
            ->whereNotNull('berlaku_sampai')
            ->max('berlaku_sampai');

        return $nilai !== null ? (string) $nilai : null;
    }

    public function sinkronKadaluarsaSimSatuSupir(string $idSupir, string $tanggal): void
    {
        DB::table('supir')
            ->where('id_supir', $idSupir)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampUpdate(['tgl_kadaluarsa_sim' => $tanggal]));
    }

    public function pindahkanDokumenSupirKeKaryawan(string $idSupir, string $idKaryawan): void
    {
        DB::table('dokumen_karyawan')
            ->whereNull('dihapus_pada')
            ->whereNull('id_karyawan')
            ->where('id_supir', $idSupir)
            ->update(RecordHelper::stampUpdate(['id_karyawan' => $idKaryawan]));
    }

    public function selaraskanKtpKaryawan(string $idKaryawan): void
    {
        $nik = trim((string) DB::table('karyawan')->where('id_karyawan', $idKaryawan)->value('nik_ktp'));
        $dokumenKtp = fn () => DB::table('dokumen_karyawan')
            ->where('id_karyawan', $idKaryawan)
            ->whereNull('dihapus_pada')
            ->whereRaw("UPPER(jenis_dokumen) = 'KTP'");

        if ($nik !== '') {
            $dokumenKtp()
                ->where(fn ($q) => $q->whereNull('nomor')->orWhere('nomor', '!=', $nik))
                ->update(RecordHelper::stampUpdate(['nomor' => $nik]));

            return;
        }

        $nomor = trim((string) $dokumenKtp()->whereNotNull('nomor')->where('nomor', '!=', '')->orderByDesc('dibuat_pada')->value('nomor'));
        if ($nomor !== '' && mb_strlen($nomor) <= 20) {
            DB::table('karyawan')
                ->where('id_karyawan', $idKaryawan)
                ->update(RecordHelper::stampUpdate(['nik_ktp' => $nomor]));
        }
    }

    public function nomorKtpTerbaru(string $idSupir, ?string $idKaryawan): ?string
    {
        $nomor = DB::table('dokumen_karyawan')
            ->whereNull('dihapus_pada')
            ->whereRaw("UPPER(jenis_dokumen) = 'KTP'")
            ->whereNotNull('nomor')
            ->where('nomor', '!=', '')
            ->where(function ($q) use ($idSupir, $idKaryawan) {
                $q->where(fn ($q2) => $q2->whereNull('id_karyawan')->where('id_supir', $idSupir));
                if ($idKaryawan !== null) {
                    $q->orWhere('id_karyawan', $idKaryawan);
                }
            })
            ->orderByDesc('dibuat_pada')
            ->value('nomor');

        return $nomor !== null ? (string) $nomor : null;
    }

    public function findById(string $id): ?object
    {
        return DB::table('dokumen_karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_dokumen_karyawan', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_dokumen_karyawan');
        DB::table('dokumen_karyawan')->insert($data);
        return $this->findById($data['id_dokumen_karyawan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('dokumen_karyawan')
            ->where('id_dokumen_karyawan', $record->id_dokumen_karyawan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_dokumen_karyawan);
    }

    public function delete(object $record): void
    {
        DB::table('dokumen_karyawan')
            ->where('id_dokumen_karyawan', $record->id_dokumen_karyawan)
            ->update(RecordHelper::stampDelete());
    }

    public function maxBerlakuSimAktif(string $idKaryawan): ?string
    {
        $nilai = DB::table('dokumen_karyawan')
            ->where('id_karyawan', $idKaryawan)
            ->whereRaw("UPPER(jenis_dokumen) = 'SIM'")
            ->whereNull('dihapus_pada')
            ->whereNotNull('berlaku_sampai')
            ->max('berlaku_sampai');
        return $nilai !== null ? (string) $nilai : null;
    }

    public function sinkronKadaluarsaSimSupir(string $idKaryawan, string $tanggal): void
    {
        DB::table('supir')
            ->where('id_karyawan', $idKaryawan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampUpdate(['tgl_kadaluarsa_sim' => $tanggal]));
    }
}
