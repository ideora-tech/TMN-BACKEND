<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('supir', function (Blueprint $table) {
            $table->char('id_supir', 36)->primary();
            $table->char('id_pengguna', 36)->nullable();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 200);
            $table->string('no_sim', 50);
            $table->string('jenis_sim', 20)->default('B1');
            $table->date('tgl_kadaluarsa_sim')->nullable();
            $table->string('telepon', 30)->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->string('foto', 255)->nullable();
            MigrationHelper::auditColumns($table);
            $table->index('id_perusahaan');
        });
    }
    public function down(): void { Schema::dropIfExists('supir'); }
};
