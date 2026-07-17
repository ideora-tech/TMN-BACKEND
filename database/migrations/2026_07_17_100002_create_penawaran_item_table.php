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
        Schema::create('penawaran_item', function (Blueprint $table) {
            $table->char('id_penawaran_item', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_penawaran', 36);
            $table->char('id_rute', 36);
            $table->char('id_jenis_kendaraan', 36);
            $table->char('id_tarif_rute', 36)->nullable(); // jejak tarif master saat quote
            $table->decimal('harga_satuan', 15, 2);
            $table->integer('estimasi_ritase')->default(1);
            $table->decimal('subtotal', 15, 2);
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_penawaran'], 'penawaran_item_penawaran_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penawaran_item');
    }
};
