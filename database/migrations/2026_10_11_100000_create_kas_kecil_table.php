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
    private string $idMenu     = 'm0000001-0000-4000-8000-000000000105';
    private string $idKeuangan = 'm0000001-0000-4000-8000-000000000030';
    private string $kodeEvent  = 'pengisian_kas_kecil';

    private array $polaJabatanApprover = ['direktur keuangan%', 'dirut keuangan%', 'direktur finance%', 'finance director%'];

    public function up(): void
    {
        if (!Schema::hasTable('kas_kecil_pengisian')) {
            Schema::create('kas_kecil_pengisian', function (Blueprint $table) {
                $table->char('id_pengisian', 36)->primary();
                $table->char('id_perusahaan', 36)->index();
                $table->string('nomor_pengisian', 30);
                $table->date('tanggal');
                $table->decimal('nominal', 15, 2);
                $table->string('keterangan', 500);
                $table->string('nama_bank', 100)->nullable();
                $table->string('nomor_rekening', 50)->nullable();
                $table->string('atas_nama_rekening', 200)->nullable();
                $table->char('id_pengajuan', 36)->nullable()->unique();
                MigrationHelper::auditColumns($table);

                $table->unique(['id_perusahaan', 'nomor_pengisian']);
            });
        }

        if (!Schema::hasTable('kas_kecil_transaksi')) {
            Schema::create('kas_kecil_transaksi', function (Blueprint $table) {
                $table->char('id_transaksi', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->string('nomor_transaksi', 30);
                $table->date('tanggal');
                $table->string('arah', 10);
                $table->string('jenis', 20);
                $table->string('kategori', 30)->nullable();
                $table->decimal('nominal', 15, 2);
                $table->string('penerima', 150)->nullable();
                $table->string('keterangan', 255);
                $table->string('url_bukti', 500)->nullable();
                $table->char('id_pengisian', 36)->nullable()->unique();
                MigrationHelper::auditColumns($table);

                $table->index(['id_perusahaan', 'tanggal']);
                $table->unique(['id_perusahaan', 'nomor_transaksi']);
            });
        }

        if (!Schema::hasColumn('pengajuan_pengeluaran', 'id_pengisian_kas_kecil')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->char('id_pengisian_kas_kecil', 36)->nullable()->unique();
            });
        }

        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenu, 'nama_menu' => 'Kas Kecil', 'path' => '/kas-kecil',
                'icon' => 'piggyBank', 'id_menu_induk' => $this->idKeuangan, 'urutan' => 7,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenu, 'kode_peran' => 'KEUANGAN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenu, 'kode_peran' => 'SUPERADMIN'],
        ]);

        $izin = [
            ['KEUANGAN', 'lihat', 1],
            ['KEUANGAN', 'tambah', 1],
            ['KEUANGAN', 'ubah', 1],
            ['KEUANGAN', 'hapus', 1],
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

        $this->siapkanApproval();
    }

    public function down(): void
    {
        DB::table('izin_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenu)->delete();
        DB::table('menu')->where('id_menu', $this->idMenu)->delete();

        if (Schema::hasColumn('pengajuan_pengeluaran', 'id_pengisian_kas_kecil')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->dropUnique(['id_pengisian_kas_kecil']);
                $table->dropColumn('id_pengisian_kas_kecil');
            });
        }

        Schema::dropIfExists('kas_kecil_transaksi');
        Schema::dropIfExists('kas_kecil_pengisian');
    }

    private function siapkanApproval(): void
    {
        $perusahaanIds = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');

        foreach ($perusahaanIds as $idPerusahaan) {
            $sudahAda = DB::table('approval_event_type')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('kode', $this->kodeEvent)
                ->whereNull('dihapus_pada')
                ->exists();
            if ($sudahAda) {
                continue;
            }

            $idEventType = (string) Str::uuid();
            DB::table('approval_event_type')->insert([
                'id_event_type' => $idEventType,
                'id_perusahaan' => $idPerusahaan,
                'kode'          => $this->kodeEvent,
                'nama'          => 'Pengisian Kas Kecil',
                'mode_resolusi' => 'pinned',
                'aktif'         => 1,
                'dibuat_pada'   => now(),
            ]);

            $jabatan = DB::table('jabatan')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('aktif', 1)
                ->whereNull('dihapus_pada')
                ->where(function ($q) {
                    foreach ($this->polaJabatanApprover as $pola) {
                        $q->orWhereRaw('LOWER(nama_jabatan) LIKE ?', [$pola]);
                    }
                })
                ->pluck('id_jabatan');

            foreach ($jabatan as $idJabatan) {
                DB::table('approval_config_approver')->insert([
                    'id_config'     => (string) Str::uuid(),
                    'id_event_type' => $idEventType,
                    'tipe'          => 'jabatan',
                    'id_jabatan'    => $idJabatan,
                    'id_pengguna'   => null,
                    'dibuat_pada'   => now(),
                ]);
            }
        }
    }
};
