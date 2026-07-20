<?php
// database/migrations/2026_07_19_100006_create_paket_perawatan_sparepart_table.php
declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paket_perawatan_sparepart', function (Blueprint $table) {
            $table->char('id_paket_perawatan_sparepart', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_jenis_perawatan', 36);
            $table->char('id_jenis_kendaraan', 36);
            $table->char('id_sparepart', 36);
            $table->unsignedInteger('qty_standar');
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(
                ['id_perusahaan', 'id_jenis_perawatan', 'id_jenis_kendaraan'],
                'paket_perawatan_sparepart_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_perawatan_sparepart');
    }
};
