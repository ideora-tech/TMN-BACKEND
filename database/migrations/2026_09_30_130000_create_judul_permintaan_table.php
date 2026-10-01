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
    private string $idMenu       = 'm0000001-0000-4000-8000-000000000102';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    public function up(): void
    {
        Schema::create('judul_permintaan', function (Blueprint $table) {
            $table->char('id_judul_permintaan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama_judul', 150);
            $table->string('tipe', 20)->default('umum');
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(['id_perusahaan', 'aktif']);
        });

        Schema::table('permintaan_pembelian', function (Blueprint $table) {
            if (!Schema::hasColumn('permintaan_pembelian', 'id_judul_permintaan')) {
                $table->char('id_judul_permintaan', 36)->nullable()->after('judul');
            }
        });

        $now = now();

        $existing = DB::table('permintaan_pembelian')
            ->whereNull('dihapus_pada')
            ->select('id_perusahaan', 'judul', 'tipe')
            ->distinct()
            ->get();

        $peta = [];
        foreach ($existing as $row) {
            $nama = trim((string) $row->judul);
            if ($nama === '') {
                continue;
            }
            $kunci = $row->id_perusahaan . '|' . mb_strtolower($nama);
            if (isset($peta[$kunci])) {
                continue;
            }
            $id = (string) Str::uuid();
            $peta[$kunci] = $id;
            DB::table('judul_permintaan')->insert([
                'id_judul_permintaan' => $id,
                'id_perusahaan'       => $row->id_perusahaan,
                'nama_judul'          => $nama,
                'tipe'                => $row->tipe ?: 'umum',
                'aktif'               => 1,
                'dibuat_pada'         => $now,
            ]);
        }

        foreach (DB::table('permintaan_pembelian')->whereNull('dihapus_pada')->get(['id_permintaan', 'id_perusahaan', 'judul']) as $pr) {
            $kunci = $pr->id_perusahaan . '|' . mb_strtolower(trim((string) $pr->judul));
            if (isset($peta[$kunci])) {
                DB::table('permintaan_pembelian')
                    ->where('id_permintaan', $pr->id_permintaan)
                    ->update(['id_judul_permintaan' => $peta[$kunci]]);
            }
        }

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Judul Permintaan', 'path' => '/judul-permintaan',
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

        Schema::table('permintaan_pembelian', function (Blueprint $table) {
            if (Schema::hasColumn('permintaan_pembelian', 'id_judul_permintaan')) {
                $table->dropColumn('id_judul_permintaan');
            }
        });

        Schema::dropIfExists('judul_permintaan');
    }
};
