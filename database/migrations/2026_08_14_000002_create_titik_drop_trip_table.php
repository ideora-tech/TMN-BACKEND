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
        Schema::create('titik_drop_trip', function (Blueprint $table) {
            $table->char('id_titik_drop', 36)->primary();
            $table->char('id_trip', 36)->index();
            $table->unsignedTinyInteger('urutan');
            $table->string('lokasi', 200);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titik_drop_trip');
    }
};
