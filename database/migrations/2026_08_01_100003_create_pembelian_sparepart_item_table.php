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
        Schema::create('pembelian_sparepart_item', function (Blueprint $table) {
            $table->char('id_item', 36)->primary();
            $table->char('id_pembelian', 36)->index();
            $table->char('id_sparepart', 36)->index();
            $table->string('nama_sparepart', 150);
            $table->integer('qty');
            $table->decimal('harga_estimasi', 15, 2)->default(0);
            $table->decimal('harga_aktual', 15, 2)->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembelian_sparepart_item');
    }
};
