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
        Schema::create('supplier', function (Blueprint $table) {
            $table->char('id_supplier', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 150);
            $table->string('telepon', 30)->nullable();
            $table->text('alamat')->nullable();
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier');
    }
};
