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
        Schema::create('supir_proyek', function (Blueprint $table) {
            $table->char('id_supir_proyek', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_proyek', 36);
            $table->char('id_supir', 36);
            MigrationHelper::auditColumns($table);
            $table->index('id_proyek');
            $table->index('id_supir');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supir_proyek');
    }
};
