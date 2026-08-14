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
        Schema::create('biaya_tagihan_trip', function (Blueprint $table) {
            $table->char('id_biaya_tagihan', 36)->primary();
            $table->char('id_laporan', 36)->index();
            $table->string('nama_biaya', 100);
            $table->decimal('nominal', 15, 2)->default(0);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biaya_tagihan_trip');
    }
};
