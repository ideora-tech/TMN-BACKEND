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
        if (!Schema::hasTable('pengeluaran_supir')) {
            Schema::create('pengeluaran_supir', function (Blueprint $table) {
                $table->char('id_pengeluaran_supir', 36)->primary();
                $table->char('id_perusahaan', 36)->index();
                $table->string('nomor', 30);
                $table->char('id_supir', 36)->index();
                $table->string('jenis', 30);
                $table->date('tanggal');
                $table->decimal('nominal', 15, 2);
                $table->char('id_armada', 36)->nullable()->index();
                $table->char('id_armada_vendor', 36)->nullable();
                $table->string('nopol', 20);
                $table->string('keterangan', 500)->nullable();
                $table->string('nama_bank', 100)->nullable();
                $table->string('nomor_rekening', 50)->nullable();
                $table->string('atas_nama_rekening', 150)->nullable();
                $table->char('id_pengajuan', 36)->nullable()->unique();
                MigrationHelper::auditColumns($table);

                $table->unique(['id_perusahaan', 'nomor']);
            });
        }

        if (!Schema::hasTable('pengeluaran_supir_foto')) {
            Schema::create('pengeluaran_supir_foto', function (Blueprint $table) {
                $table->char('id_foto', 36)->primary();
                $table->char('id_pengeluaran_supir', 36)->index();
                $table->string('url_foto', 255);
                $table->unsignedTinyInteger('urutan')->default(1);
                MigrationHelper::auditColumns($table);
            });
        }

        if (!Schema::hasColumn('pengajuan_pengeluaran', 'id_pengeluaran_supir')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->char('id_pengeluaran_supir', 36)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pengajuan_pengeluaran', 'id_pengeluaran_supir')) {
            Schema::table('pengajuan_pengeluaran', function (Blueprint $table) {
                $table->dropUnique(['id_pengeluaran_supir']);
                $table->dropColumn('id_pengeluaran_supir');
            });
        }

        Schema::dropIfExists('pengeluaran_supir_foto');
        Schema::dropIfExists('pengeluaran_supir');
    }
};
