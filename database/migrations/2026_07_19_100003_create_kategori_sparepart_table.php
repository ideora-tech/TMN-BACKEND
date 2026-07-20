<?php
// database/migrations/2026_07_19_100003_create_kategori_sparepart_table.php
declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori_sparepart', function (Blueprint $table) {
            $table->char('id_kategori_sparepart', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 100);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_sparepart');
    }
};
