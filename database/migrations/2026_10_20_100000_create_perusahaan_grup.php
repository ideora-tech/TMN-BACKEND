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
        if (!Schema::hasTable('perusahaan_grup')) {
            Schema::create('perusahaan_grup', function (Blueprint $table) {
                $table->char('id_perusahaan_grup', 36)->primary();
                $table->char('id_perusahaan', 36);
                $table->string('singkatan', 30);
                $table->string('nama_lengkap', 150)->nullable();
                $table->tinyInteger('aktif')->default(1);
                MigrationHelper::auditColumns($table);

                $table->index(['id_perusahaan', 'singkatan']);
            });
        }

        if (!Schema::hasColumn('armada', 'id_perusahaan_grup')) {
            Schema::table('armada', function (Blueprint $table) {
                $table->char('id_perusahaan_grup', 36)->nullable()->after('id_vendor');
                $table->index('id_perusahaan_grup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('armada', 'id_perusahaan_grup')) {
            Schema::table('armada', function (Blueprint $table) {
                $table->dropIndex(['id_perusahaan_grup']);
                $table->dropColumn('id_perusahaan_grup');
            });
        }

        Schema::dropIfExists('perusahaan_grup');
    }
};
