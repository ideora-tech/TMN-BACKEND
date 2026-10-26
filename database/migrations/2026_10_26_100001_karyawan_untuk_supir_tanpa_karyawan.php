<?php

declare(strict_types=1);

use App\Support\KodeOtomatis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $jabatanSupir = [];

    public function up(): void
    {
        $dokumenPunyaSupir = Schema::hasColumn('dokumen_karyawan', 'id_supir');

        $daftarSupir = DB::table('supir')
            ->whereNull('dihapus_pada')
            ->whereNull('id_karyawan')
            ->orderBy('dibuat_pada')
            ->orderBy('id_supir')
            ->pluck('id_supir');

        foreach ($daftarSupir as $idSupir) {
            DB::transaction(function () use ($idSupir, $dokumenPunyaSupir) {
                $supir = DB::table('supir')
                    ->where('id_supir', $idSupir)
                    ->whereNull('dihapus_pada')
                    ->whereNull('id_karyawan')
                    ->lockForUpdate()
                    ->first();
                if ($supir === null) {
                    return;
                }

                $idPerusahaan = (string) $supir->id_perusahaan;
                $idKaryawan = (string) Str::uuid();

                DB::table('karyawan')->insert([
                    'id_karyawan'   => $idKaryawan,
                    'id_perusahaan' => $idPerusahaan,
                    'id_jabatan'    => $this->idJabatanSupir($idPerusahaan),
                    'nik'           => $this->nikBaru($idPerusahaan),
                    'nama_karyawan' => $supir->nama,
                    'telepon'       => $supir->telepon,
                    'aktif'         => $supir->status === 'aktif' ? 1 : 0,
                    'dibuat_pada'   => now(),
                ]);

                DB::table('supir')->where('id_supir', $idSupir)->update([
                    'id_karyawan' => $idKaryawan,
                    'diubah_pada' => now(),
                ]);

                if (!$dokumenPunyaSupir) {
                    return;
                }

                DB::table('dokumen_karyawan')
                    ->whereNull('dihapus_pada')
                    ->whereNull('id_karyawan')
                    ->where('id_supir', $idSupir)
                    ->update(['id_karyawan' => $idKaryawan, 'diubah_pada' => now()]);

                $nomorKtp = trim((string) DB::table('dokumen_karyawan')
                    ->where('id_karyawan', $idKaryawan)
                    ->whereNull('dihapus_pada')
                    ->whereRaw("UPPER(jenis_dokumen) = 'KTP'")
                    ->whereNotNull('nomor')
                    ->where('nomor', '!=', '')
                    ->orderByDesc('dibuat_pada')
                    ->value('nomor'));
                if ($nomorKtp !== '' && mb_strlen($nomorKtp) <= 20) {
                    DB::table('karyawan')->where('id_karyawan', $idKaryawan)->update(['nik_ktp' => $nomorKtp]);
                }
            });
        }
    }

    public function down(): void
    {
    }

    private function idJabatanSupir(string $idPerusahaan): ?string
    {
        if (!array_key_exists($idPerusahaan, $this->jabatanSupir)) {
            $id = DB::table('jabatan')
                ->whereNull('dihapus_pada')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('aktif', 1)
                ->where(fn ($q) => $q
                    ->where('is_supir', 1)
                    ->orWhereRaw('LOWER(TRIM(nama_jabatan)) LIKE ?', ['supir%'])
                    ->orWhereRaw('LOWER(TRIM(nama_jabatan)) LIKE ?', ['pengemudi%']))
                ->orderByDesc('is_supir')
                ->orderBy('dibuat_pada')
                ->value('id_jabatan');
            $this->jabatanSupir[$idPerusahaan] = $id !== null ? (string) $id : null;
        }

        return $this->jabatanSupir[$idPerusahaan];
    }

    private function nikBaru(string $idPerusahaan): string
    {
        do {
            $nik = KodeOtomatis::berikutnya($idPerusahaan, 'nik_supir');
        } while (DB::table('karyawan')->where('nik', $nik)->exists());

        return $nik;
    }
};
