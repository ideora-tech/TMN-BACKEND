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
    private string $idMenu = 'a7d3f2c8-6b14-4e59-8c0a-3f9e1d5b7a26';
    private string $idHr   = 'm0000001-0000-4000-8000-000000000060';

    public function up(): void
    {
        if (!Schema::hasTable('kasbon')) {
            Schema::create('kasbon', function (Blueprint $table) {
                $table->char('id_kasbon', 36)->primary();
                $table->char('id_perusahaan', 36)->index();
                $table->string('nomor_kasbon', 30);
                $table->char('id_karyawan', 36)->index();
                $table->date('tanggal');
                $table->decimal('nominal', 15, 2);
                $table->decimal('cicilan_per_periode', 15, 2);
                $table->date('mulai_potong');
                $table->string('keperluan', 500);
                $table->string('nama_bank', 100)->nullable();
                $table->string('nomor_rekening', 50)->nullable();
                $table->tinyInteger('saldo_awal')->default(0);
                $table->char('id_pengajuan', 36)->nullable()->unique();
                MigrationHelper::auditColumns($table);

                $table->unique(['id_perusahaan', 'nomor_kasbon']);
            });
        }

        if (!Schema::hasTable('kasbon_pembayaran')) {
            Schema::create('kasbon_pembayaran', function (Blueprint $table) {
                $table->char('id_kasbon_pembayaran', 36)->primary();
                $table->char('id_kasbon', 36)->index();
                $table->date('tanggal');
                $table->decimal('nominal', 15, 2);
                $table->string('sumber', 20);
                $table->char('id_periode', 36)->nullable()->index();
                $table->char('id_slip', 36)->nullable();
                $table->char('id_pemasukan', 36)->nullable();
                $table->string('keterangan', 255)->nullable();
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('pengajuan_pengeluaran', 'id_kasbon')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->char('id_kasbon', 36)->nullable()->unique();
            });
        }

        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Kasbon', 'path' => '/kasbon',
                'icon' => 'handCoins', 'id_menu_induk' => $this->idHr, 'urutan' => 6,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        foreach (['KEUANGAN', 'MANAGER', 'ADMIN'] as $kodePeran) {
            foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
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
                    'diizinkan'     => 1,
                    'dibuat_pada'   => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();

        if (Schema::hasColumn('pengajuan_pengeluaran', 'id_kasbon')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->dropUnique(['id_kasbon']);
                $table->dropColumn('id_kasbon');
            });
        }

        Schema::dropIfExists('kasbon_pembayaran');
        Schema::dropIfExists('kasbon');
    }
};
