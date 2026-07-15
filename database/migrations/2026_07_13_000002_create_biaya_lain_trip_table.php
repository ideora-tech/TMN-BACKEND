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
        Schema::create('biaya_lain_trip', function (Blueprint $table) {
            $table->char('id_biaya_lain', 36)->primary();
            $table->char('id_laporan', 36);
            $table->string('nama_biaya', 100);
            $table->decimal('nominal', 15, 2)->default(0);
            MigrationHelper::auditColumns($table);
            $table->index('id_laporan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biaya_lain_trip');
    }
};
