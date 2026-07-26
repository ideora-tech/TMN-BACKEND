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
    public function up(): void
    {
        Schema::create('jenis_cuti', function (Blueprint $table) {
            $table->char('id_jenis_cuti', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama_jenis', 100);
            $table->tinyInteger('mengurangi_saldo')->default(1);
            $table->tinyInteger('aktif')->default(1);
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_perusahaan', 'jenis_cuti_perusahaan_idx');
        });

        Schema::create('saldo_cuti', function (Blueprint $table) {
            $table->char('id_saldo_cuti', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36)->nullable();
            $table->char('id_supir', 36)->nullable();
            $table->integer('tahun');
            $table->string('tipe', 20); // debit, penyesuaian
            $table->integer('jumlah_hari'); // penyesuaian boleh negatif
            $table->char('referensi_id', 36)->nullable(); // id_pengajuan saat debit cuti
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_karyawan', 'tahun'], 'saldo_cuti_karyawan_idx');
            $table->index(['id_supir', 'tahun'], 'saldo_cuti_supir_idx');
        });

        Schema::create('pengajuan_cuti', function (Blueprint $table) {
            $table->char('id_pengajuan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36)->nullable();
            $table->char('id_supir', 36)->nullable();
            $table->char('id_jenis_cuti', 36);
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->integer('jumlah_hari');
            $table->text('alasan')->nullable();
            $table->string('status', 20)->default('menunggu'); // menunggu, disetujui, ditolak, dibatalkan
            $table->char('diproses_oleh', 36)->nullable();
            $table->dateTime('diproses_pada')->nullable();
            $table->text('catatan_proses')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_karyawan', 'status'], 'pengajuan_cuti_karyawan_idx');
            $table->index(['id_supir', 'status'], 'pengajuan_cuti_supir_idx');
            $table->index('tanggal_mulai', 'pengajuan_cuti_tanggal_idx');
        });

        // Seed jenis cuti default untuk setiap perusahaan yang sudah ada
        $now = now();
        $defaults = [
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo' => 1, 'keterangan' => 'Cuti tahunan reguler, memotong saldo'],
            ['nama' => 'Sakit',        'mengurangi_saldo' => 0, 'keterangan' => 'Izin sakit (disarankan lampirkan surat dokter)'],
            ['nama' => 'Izin',         'mengurangi_saldo' => 0, 'keterangan' => 'Izin keperluan pribadi'],
            ['nama' => 'Cuti Khusus',  'mengurangi_saldo' => 0, 'keterangan' => 'Menikah, duka, melahirkan, dan cuti khusus lain'],
        ];

        $perusahaan = DB::table('perusahaan')->whereNull('dihapus_pada')->pluck('id_perusahaan');
        foreach ($perusahaan as $idPerusahaan) {
            foreach ($defaults as $jenis) {
                DB::table('jenis_cuti')->insert([
                    'id_jenis_cuti'    => (string) Str::uuid(),
                    'id_perusahaan'    => $idPerusahaan,
                    'nama_jenis'       => $jenis['nama'],
                    'mengurangi_saldo' => $jenis['mengurangi_saldo'],
                    'aktif'            => 1,
                    'keterangan'       => $jenis['keterangan'],
                    'dibuat_pada'      => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_cuti');
        Schema::dropIfExists('saldo_cuti');
        Schema::dropIfExists('jenis_cuti');
    }
};
