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
        Schema::create('evaluasi_trip', function (Blueprint $table) {
            $table->char('id_evaluasi', 36)->primary();
            $table->char('id_penugasan', 36)->unique(); // one per penugasan
            $table->tinyInteger('nilai_armada')->nullable(); // 1-5
            $table->tinyInteger('nilai_supir')->nullable(); // 1-5
            $table->text('catatan')->nullable();
            $table->char('id_dievaluasi_oleh', 36)->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluasi_trip');
    }
};
