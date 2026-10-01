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
    private string $idMenu        = 'e4c7a9b1-3d52-4f86-9a0e-7b1c5d2f8e63';
    private string $idOperasional = 'm0000001-0000-4000-8000-000000000020';

    public function up(): void
    {
        if (!Schema::hasTable('uang_jalan')) {
            Schema::create('uang_jalan', function (Blueprint $table) {
                $table->char('id_uang_jalan', 36)->primary();
                $table->char('id_perusahaan', 36)->index();
                $table->string('nomor_uang_jalan', 30);
                $table->date('tanggal');
                $table->string('nama_driver', 150);
                $table->string('tipe_driver', 10);
                $table->string('nama_vendor', 150)->nullable();
                $table->string('nopol', 20);
                $table->string('rute', 255);
                $table->decimal('uang_jalan_per_trip', 15, 2);
                $table->unsignedSmallInteger('jumlah_trip');
                $table->decimal('nominal', 15, 2);
                $table->text('catatan')->nullable();
                $table->string('nomor_rekening', 50);
                $table->string('nama_bank', 100);
                $table->char('id_pengajuan', 36)->nullable()->unique();
                MigrationHelper::auditColumns($table);

                $table->unique(['id_perusahaan', 'nomor_uang_jalan']);
                $table->index(['id_perusahaan', 'tanggal']);
            });
        }

        if (!Schema::hasColumn('pengajuan_pengeluaran', 'id_uang_jalan')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->char('id_uang_jalan', 36)->nullable()->unique();
            });
        }

        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Uang Jalan', 'path' => '/uang-jalan',
                'icon' => 'wallet', 'id_menu_induk' => $this->idOperasional, 'urutan' => 10,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['DISPATCHER', 'lihat', 1],
            ['DISPATCHER', 'tambah', 1],
            ['DISPATCHER', 'ubah', 1],
            ['DISPATCHER', 'hapus', 1],
            ['KEUANGAN', 'lihat', 1],
            ['KEUANGAN', 'tambah', 1],
            ['KEUANGAN', 'ubah', 1],
            ['KEUANGAN', 'hapus', 1],
            ['MANAGER', 'lihat', 1],
            ['MANAGER', 'tambah', 1],
            ['MANAGER', 'ubah', 1],
            ['MANAGER', 'hapus', 1],
            ['ADMIN', 'lihat', 1],
            ['ADMIN', 'tambah', 1],
            ['ADMIN', 'ubah', 1],
            ['ADMIN', 'hapus', 1],
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

        if (Schema::hasColumn('pengajuan_pengeluaran', 'id_uang_jalan')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->dropUnique(['id_uang_jalan']);
                $table->dropColumn('id_uang_jalan');
            });
        }

        Schema::dropIfExists('uang_jalan');
    }
};
