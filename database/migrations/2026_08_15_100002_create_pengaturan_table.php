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
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->char('id_pengaturan', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kunci', 50);
            $table->text('nilai')->nullable();
            MigrationHelper::auditColumns($table);

            $table->index(['id_perusahaan', 'kunci'], 'pengaturan_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
    }
};
