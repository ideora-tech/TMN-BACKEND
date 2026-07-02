<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('trip', function (Blueprint $table) {
            $table->char('id_trip', 36)->primary();
            $table->char('id_jadwal', 36)->unique();
            $table->dateTime('waktu_checkin')->nullable();
            $table->dateTime('waktu_checkout')->nullable();
            $table->enum('status', ['belum_mulai', 'berjalan', 'selesai', 'dibatalkan'])->default('belum_mulai');
            $table->text('catatan')->nullable();
            MigrationHelper::auditColumns($table);
            $table->index('id_jadwal');
        });
    }
    public function down(): void { Schema::dropIfExists('trip'); }
};
