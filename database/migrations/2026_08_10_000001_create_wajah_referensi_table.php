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
        Schema::create('wajah_referensi', function (Blueprint $table) {
            $table->char('id_wajah', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->char('id_pengguna', 36)->unique();
            $table->string('path_foto', 255);
            $table->text('embedding');
            $table->string('model_versi', 50)->default('mobilefacenet-v1');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wajah_referensi');
    }
};
