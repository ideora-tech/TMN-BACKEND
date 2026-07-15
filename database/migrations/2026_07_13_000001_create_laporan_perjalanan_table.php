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
        Schema::create('laporan_perjalanan', function (Blueprint $table) {
            $table->char('id_laporan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_trip', 36)->unique();
            $table->decimal('biaya_bbm', 15, 2)->default(0);
            $table->decimal('jarak_tempuh_km', 10, 2)->nullable();
            $table->decimal('uang_jalan', 15, 2)->default(0);
            $table->text('catatan_insiden')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_perjalanan');
    }
};
