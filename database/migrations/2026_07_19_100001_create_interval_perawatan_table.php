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
        Schema::create('interval_perawatan', function (Blueprint $table) {
            $table->char('id_interval_perawatan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_jenis_perawatan', 36);
            $table->char('id_jenis_kendaraan', 36);
            $table->unsignedInteger('interval_hari');
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(
                ['id_perusahaan', 'id_jenis_perawatan', 'id_jenis_kendaraan'],
                'interval_perawatan_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interval_perawatan');
    }
};
