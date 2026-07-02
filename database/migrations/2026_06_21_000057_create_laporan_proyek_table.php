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
        Schema::create('laporan_proyek', function (Blueprint $table) {
            $table->char('id_laporan', 36)->primary();
            $table->char('id_proyek', 36)->unique(); // one per proyek
            $table->text('ringkasan')->nullable();
            $table->integer('total_trip')->default(0);
            $table->char('id_diserahkan_oleh', 36)->nullable();
            $table->dateTime('diserahkan_pada')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_proyek');
    }
};
