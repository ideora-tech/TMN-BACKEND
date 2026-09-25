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
        Schema::create('permintaan_pembelian', function (Blueprint $table) {
            $table->char('id_permintaan', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('nomor_permintaan', 30);
            $table->char('id_pengaju', 36)->index();
            $table->char('id_departemen', 36)->nullable()->index();
            $table->date('tanggal_permintaan');
            $table->date('tanggal_dibutuhkan')->nullable();
            $table->string('judul', 150);
            $table->text('alasan');
            $table->string('status', 30)->default('diajukan');
            $table->char('id_supplier', 36)->nullable()->index();
            $table->decimal('total_estimasi', 15, 2)->default(0);
            $table->decimal('total_aktual', 15, 2)->nullable();
            $table->date('tanggal_pembelian')->nullable();
            $table->date('tanggal_diterima')->nullable();
            $table->date('tanggal_pembayaran')->nullable();
            $table->text('alasan_ditolak')->nullable();
            $table->text('alasan_batal')->nullable();
            $table->char('diproses_oleh', 36)->nullable();
            $table->dateTime('diproses_pada')->nullable();
            $table->char('dibeli_oleh', 36)->nullable();
            $table->dateTime('dibeli_pada')->nullable();
            $table->char('diterima_oleh', 36)->nullable();
            $table->dateTime('diterima_pada')->nullable();
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'nomor_permintaan']);
            $table->index(['id_perusahaan', 'status']);
        });

        Schema::create('permintaan_pembelian_item', function (Blueprint $table) {
            $table->char('id_item', 36)->primary();
            $table->char('id_permintaan', 36)->index();
            $table->string('jenis', 30);
            $table->char('id_barang', 36)->nullable()->index();
            $table->string('nama_item', 150);
            $table->text('spesifikasi')->nullable();
            $table->integer('qty');
            $table->string('satuan', 30);
            $table->decimal('harga_estimasi', 15, 2)->default(0);
            $table->decimal('harga_aktual', 15, 2)->nullable();
            $table->integer('qty_diterima')->nullable();
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);
        });

        Schema::create('permintaan_pembelian_bukti', function (Blueprint $table) {
            $table->char('id_bukti', 36)->primary();
            $table->char('id_permintaan', 36)->index();
            $table->enum('tahap', ['pengajuan', 'pembelian', 'penerimaan']);
            $table->string('url_file', 255);
            $table->string('nama_asli', 255);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_pembelian_bukti');
        Schema::dropIfExists('permintaan_pembelian_item');
        Schema::dropIfExists('permintaan_pembelian');
    }
};
