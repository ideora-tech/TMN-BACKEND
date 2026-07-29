<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_payroll', function (Blueprint $table) {
            $table->char('id_pengaturan', 36)->primary();
            $table->char('id_perusahaan', 36)->unique();
            $table->integer('tanggal_mulai_cutoff')->default(21); // periode = tgl ini bulan lalu s/d (tgl-1) bulan berjalan; 1 = bulan kalender
            $table->integer('hari_kerja_per_bulan')->default(25); // pembagi potongan alpha prorata
            MigrationHelper::auditColumns($table);
        });

        Schema::create('payroll_periode', function (Blueprint $table) {
            $table->char('id_periode', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 100);
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('status', 20)->default('draft'); // draft, final
            $table->dateTime('difinalisasi_pada')->nullable();
            $table->char('difinalisasi_oleh', 36)->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_perusahaan', 'tanggal_mulai'], 'payroll_periode_perusahaan_idx');
        });

        Schema::create('payroll_slip', function (Blueprint $table) {
            $table->char('id_slip', 36)->primary();
            $table->char('id_periode', 36);
            $table->char('id_perusahaan', 36);
            $table->char('id_karyawan', 36);
            $table->decimal('gaji_pokok', 15, 2)->default(0);
            $table->decimal('upah_lembur', 15, 2)->default(0);
            $table->integer('menit_lembur')->default(0);
            $table->decimal('tunjangan_lain', 15, 2)->default(0);
            $table->string('keterangan_tunjangan', 255)->nullable();
            $table->integer('jumlah_alpha')->default(0);
            $table->decimal('potongan_absen', 15, 2)->default(0);
            $table->decimal('potongan_bpjs_kesehatan', 15, 2)->default(0);
            $table->decimal('potongan_bpjs_tk', 15, 2)->default(0);
            $table->decimal('pph21', 15, 2)->default(0);
            $table->decimal('potongan_lain', 15, 2)->default(0);
            $table->string('keterangan_potongan', 255)->nullable();
            $table->decimal('total_bruto', 15, 2)->default(0);
            $table->decimal('total_potongan', 15, 2)->default(0);
            $table->decimal('gaji_bersih', 15, 2)->default(0);
            $table->text('catatan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_periode', 'payroll_slip_periode_idx');
            $table->index('id_karyawan', 'payroll_slip_karyawan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_slip');
        Schema::dropIfExists('payroll_periode');
        Schema::dropIfExists('pengaturan_payroll');
    }
};
