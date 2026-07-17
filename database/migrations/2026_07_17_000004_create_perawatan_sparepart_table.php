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
        Schema::create('perawatan_sparepart', function (Blueprint $table) {
            $table->char('id_perawatan_sparepart', 36)->primary();
            $table->char('id_perawatan', 36)->index();
            $table->char('id_sparepart', 36)->index();
            $table->string('nama_sparepart', 150); // snapshot nama saat dipakai
            $table->integer('qty');
            $table->decimal('harga', 15, 2)->default(0); // harga aktual per unit
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perawatan_sparepart');
    }
};
