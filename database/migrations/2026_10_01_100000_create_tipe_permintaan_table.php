<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenu       = 'm0000001-0000-4000-8000-000000000103';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    private array $bawaan = [
        'umum'      => 'Umum (Barang/Jasa)',
        'sparepart' => 'Spare Part',
        'aset'      => 'Aset / Unit Armada Baru',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('tipe_permintaan')) {
            Schema::create('tipe_permintaan', function (Blueprint $table) {
                $table->char('id_tipe_permintaan', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->string('nama_tipe', 100);
                $table->string('jenis_form', 20);
                $table->tinyInteger('aktif')->default(1);
                MigrationHelper::auditColumns($table);

                $table->index(['id_perusahaan', 'aktif']);
            });
        }

        if (!Schema::hasColumn('judul_permintaan', 'id_tipe_permintaan')) {
            Schema::table('judul_permintaan', function (Blueprint $table) {
                $table->char('id_tipe_permintaan', 36)->nullable()->index();
            });
        }

        $now = now();
        $idPerusahaanList = DB::table('perusahaan')->pluck('id_perusahaan')
            ->merge(DB::table('judul_permintaan')->distinct()->pluck('id_perusahaan'))
            ->unique()
            ->values();

        foreach ($idPerusahaanList as $idPerusahaan) {
            foreach ($this->bawaan as $jenisForm => $nama) {
                $idTipe = DB::table('tipe_permintaan')
                    ->where('id_perusahaan', $idPerusahaan)
                    ->where('jenis_form', $jenisForm)
                    ->whereNull('dihapus_pada')
                    ->value('id_tipe_permintaan');

                if ($idTipe === null) {
                    $idTipe = (string) Str::uuid();
                    DB::table('tipe_permintaan')->insert([
                        'id_tipe_permintaan' => $idTipe,
                        'id_perusahaan'      => $idPerusahaan,
                        'nama_tipe'          => $nama,
                        'jenis_form'         => $jenisForm,
                        'aktif'              => 1,
                        'dibuat_pada'        => $now,
                    ]);
                }

                DB::table('judul_permintaan')
                    ->where('id_perusahaan', $idPerusahaan)
                    ->where('tipe', $jenisForm)
                    ->whereNull('id_tipe_permintaan')
                    ->update(['id_tipe_permintaan' => $idTipe]);
            }
        }

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Tipe Permintaan', 'path' => '/tipe-permintaan',
                'icon' => 'clipboardList', 'id_menu_induk' => $this->idDataMaster, 'urutan' => 9,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'PENGADAAN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['PENGADAAN', 'lihat', 1],
            ['PENGADAAN', 'tambah', 1],
            ['PENGADAAN', 'ubah', 1],
            ['PENGADAAN', 'hapus', 1],
            ['MANAGER', 'lihat', 1],
            ['MANAGER', 'tambah', 0],
            ['MANAGER', 'ubah', 0],
            ['MANAGER', 'hapus', 0],
            ['ADMIN', 'lihat', 1],
            ['ADMIN', 'tambah', 0],
            ['ADMIN', 'ubah', 0],
            ['ADMIN', 'hapus', 0],
        ];

        foreach ($izin as [$kodePeran, $aksi, $diizinkan]) {
            $exists = DB::table('izin_peran')
                ->where('id_menu', $this->idMenu)
                ->where('kode_peran', $kodePeran)
                ->where('aksi', $aksi)
                ->whereNull('id_perusahaan')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => $kodePeran,
                'id_menu'       => $this->idMenu,
                'aksi'          => $aksi,
                'diizinkan'     => $diizinkan,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();

        if (Schema::hasColumn('judul_permintaan', 'id_tipe_permintaan')) {
            Schema::table('judul_permintaan', function (Blueprint $table) {
                $table->dropIndex(['id_tipe_permintaan']);
                $table->dropColumn('id_tipe_permintaan');
            });
        }

        Schema::dropIfExists('tipe_permintaan');
    }
};
