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
        Schema::create('approver_keuangan', function (Blueprint $table) {
            $table->char('id_approver', 36)->primary();
            $table->char('id_perusahaan', 36)->index();
            $table->string('tipe', 10);
            $table->char('id_jabatan', 36)->nullable();
            $table->char('id_pengguna', 36)->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_keuangan');
    }
};
