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
        Schema::create('dokumen_karyawan', function (Blueprint $table) {
            $table->char('id_dokumen_karyawan', 36)->primary();
            $table->char('id_karyawan', 36);
            $table->string('jenis_dokumen', 50); // KTP, NPWP, Ijazah, Kontrak Kerja, Sertifikat, Lainnya
            $table->string('nomor', 100)->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->string('url_file', 500)->nullable();
            MigrationHelper::auditColumns($table);

            $table->index('id_karyawan', 'dokumen_karyawan_karyawan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_karyawan');
    }
};
