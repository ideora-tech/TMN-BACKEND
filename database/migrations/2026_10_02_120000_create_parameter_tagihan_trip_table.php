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
        if (Schema::hasTable('parameter_tagihan_trip')) {
            return;
        }

        Schema::create('parameter_tagihan_trip', function (Blueprint $table) {
            $table->char('id_parameter_tagihan', 36)->primary();
            $table->char('id_trip', 36)->unique();
            $table->unsignedSmallInteger('jumlah_overnight')->default(0);
            $table->decimal('tarif_overnight', 15, 2)->nullable();
            $table->unsignedSmallInteger('jumlah_add_drop')->default(0);
            $table->decimal('tarif_add_drop', 15, 2)->nullable();
            $table->tinyInteger('cross_cluster')->default(0);
            $table->decimal('tarif_cross_cluster', 15, 2)->nullable();
            $table->tinyInteger('cancellation')->default(0);
            $table->decimal('tarif_cancellation', 15, 2)->nullable();
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_tagihan_trip');
    }
};
