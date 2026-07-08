<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Helpers\MigrationHelper;

return new class extends Migration {
    public function up(): void {
        Schema::create('rute', function (Blueprint $table) {
            $table->char('id_rute', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode_rute', 50);
            $table->string('nama_rute', 200);
            $table->string('asal', 200)->nullable();
            $table->string('tujuan', 200)->nullable();
            $table->decimal('estimasi_jarak_km', 8, 2)->nullable();
            $table->integer('estimasi_durasi_menit')->nullable();
            $table->text('keterangan')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }
    public function down(): void { Schema::dropIfExists('rute'); }
};