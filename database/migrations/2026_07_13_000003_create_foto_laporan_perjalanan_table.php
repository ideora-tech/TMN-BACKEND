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
        Schema::create('foto_laporan_perjalanan', function (Blueprint $table) {
            $table->char('id_foto', 36)->primary();
            $table->char('id_laporan', 36);
            $table->string('url_file', 500);
            $table->string('keterangan', 200)->nullable();
            MigrationHelper::auditColumns($table);
            $table->index('id_laporan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_laporan_perjalanan');
    }
};
