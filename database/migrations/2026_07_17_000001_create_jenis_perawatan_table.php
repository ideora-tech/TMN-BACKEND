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
        Schema::create('jenis_perawatan', function (Blueprint $table) {
            $table->char('id_jenis_perawatan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_perawatan');
    }
};
