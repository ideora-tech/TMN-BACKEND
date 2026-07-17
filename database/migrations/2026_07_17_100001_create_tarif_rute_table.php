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
        Schema::create('tarif_rute', function (Blueprint $table) {
            $table->char('id_tarif_rute', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_rute', 36);
            $table->char('id_jenis_kendaraan', 36);
            $table->char('id_klien', 36)->nullable(); // NULL = harga umum
            $table->decimal('harga', 15, 2); // harga jual flat per trip sekali jalan
            $table->decimal('estimasi_tol', 15, 2)->nullable();
            $table->decimal('estimasi_bbm', 15, 2)->nullable();
            $table->decimal('estimasi_uang_jalan', 15, 2)->nullable();
            $table->decimal('estimasi_biaya_lain', 15, 2)->nullable();
            $table->date('tanggal_mulai');
            $table->date('tanggal_berakhir')->nullable(); // NULL = masih berlaku
            $table->text('keterangan')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(
                ['id_perusahaan', 'id_rute', 'id_jenis_kendaraan', 'id_klien', 'tanggal_mulai'],
                'tarif_rute_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarif_rute');
    }
};
