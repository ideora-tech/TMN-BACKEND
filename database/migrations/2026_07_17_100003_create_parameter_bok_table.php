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
        Schema::create('parameter_bok', function (Blueprint $table) {
            $table->char('id_parameter_bok', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_jenis_kendaraan', 36); // satu baris aktif per jenis per perusahaan (dijaga Service)
            $table->char('id_jenis_bbm', 36); // harga diambil dari harga_bbm terkini
            $table->decimal('konsumsi_km_per_liter', 8, 2);
            $table->decimal('biaya_ban_per_km', 15, 2)->default(0);
            $table->decimal('biaya_servis_per_km', 15, 2)->default(0);
            $table->decimal('biaya_tetap_bulanan', 15, 2)->default(0); // penyusutan + gaji supir + asuransi/KIR
            $table->decimal('utilisasi_km_per_bulan', 10, 2);
            $table->decimal('margin_persen', 5, 2)->default(15);
            $table->text('keterangan')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(['id_perusahaan', 'id_jenis_kendaraan'], 'parameter_bok_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_bok');
    }
};
