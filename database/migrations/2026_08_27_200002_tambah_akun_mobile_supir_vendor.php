<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    private const IZIN = [
        ['/trip', ['lihat', 'tambah', 'ubah', 'hapus']],
        ['/supir', ['lihat']],
    ];

    public function up(): void
    {
        Schema::table('supir_vendor', function (Blueprint $table) {
            $table->char('id_pengguna', 36)->nullable()->after('id_vendor')->index();
        });

        $now = now();

        foreach (DB::table('perusahaan')->pluck('id_perusahaan') as $idPerusahaan) {
            $sudahAda = DB::table('peran')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode_peran', 'SUPIR_VENDOR')
                ->exists();
            if (!$sudahAda) {
                DB::table('peran')->insert([
                    'id_peran'      => (string) Str::uuid(),
                    'id_perusahaan' => $idPerusahaan,
                    'kode_peran'    => 'SUPIR_VENDOR',
                    'nama_peran'    => 'Supir Vendor',
                    'is_platform'   => 0,
                    'aktif'         => 1,
                ]);
            }
        }

        foreach (self::IZIN as [$path, $aksiList]) {
            $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
            if ($idMenu === null) {
                continue;
            }
            foreach ($aksiList as $aksi) {
                $sudahAda = DB::table('izin_peran')
                    ->where('id_menu', $idMenu)
                    ->where('kode_peran', 'SUPIR_VENDOR')
                    ->where('aksi', $aksi)
                    ->exists();
                if ($sudahAda) {
                    continue;
                }
                DB::table('izin_peran')->insert([
                    'id_izin'     => (string) Str::uuid(),
                    'kode_peran'  => 'SUPIR_VENDOR',
                    'id_menu'     => $idMenu,
                    'aksi'        => $aksi,
                    'diizinkan'   => 1,
                    'dibuat_pada' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('kode_peran', 'SUPIR_VENDOR')->delete();
        DB::table('peran')->where('kode_peran', 'SUPIR_VENDOR')->delete();

        Schema::table('supir_vendor', function (Blueprint $table) {
            $table->dropColumn('id_pengguna');
        });
    }
};
