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
        Schema::create('jadwal_shift', function (Blueprint $table) {
            $table->char('id_jadwal_shift', 36)->primary();
            $table->char('id_proyek', 36);
            $table->char('id_shift', 36);
            $table->char('id_supir', 36);
            $table->date('tanggal');
            MigrationHelper::auditColumns($table);
            $table->index(['id_supir', 'tanggal']);
            $table->index(['id_proyek', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_shift');
    }
};
