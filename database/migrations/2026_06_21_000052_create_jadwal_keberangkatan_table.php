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
        Schema::create('jadwal_keberangkatan', function (Blueprint $table) {
            $table->char('id_jadwal', 36)->primary();
            $table->char('id_penugasan', 36);
            $table->dateTime('waktu_berangkat')->nullable();
            $table->text('rute')->nullable();
            $table->dateTime('estimasi_tiba')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_keberangkatan');
    }
};
