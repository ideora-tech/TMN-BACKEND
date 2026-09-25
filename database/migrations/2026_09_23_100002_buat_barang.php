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
        Schema::create('barang', function (Blueprint $table) {
            $table->char('id_barang', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->char('id_kategori_barang', 36)->nullable()->index();
            $table->string('satuan', 30)->default('pcs');
            $table->decimal('harga_standar', 15, 2)->default(0);
            $table->integer('stok')->default(0);
            $table->integer('stok_minimum')->default(0);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'kode']);
        });

        Schema::create('barang_mutasi', function (Blueprint $table) {
            $table->char('id_mutasi', 36)->primary();
            $table->char('id_barang', 36)->index();
            $table->enum('jenis', ['masuk', 'keluar', 'penyesuaian']);
            $table->integer('qty');
            $table->decimal('harga', 15, 2)->nullable();
            $table->char('id_permintaan_pembelian', 36)->nullable()->index();
            $table->string('pemakai', 150)->nullable();
            $table->text('keterangan')->nullable();
            $table->date('tanggal');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang_mutasi');
        Schema::dropIfExists('barang');
    }
};
