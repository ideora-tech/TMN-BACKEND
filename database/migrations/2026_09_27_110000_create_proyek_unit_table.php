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
        Schema::create('proyek_unit', function (Blueprint $table) {
            $table->char('id_proyek_unit', 36)->primary();
            $table->char('id_perusahaan', 36)->index('idx_proyek_unit_id_perusahaan');
            $table->char('id_proyek', 36)->index('idx_proyek_unit_id_proyek');
            $table->string('sumber', 20);
            $table->char('id_armada', 36)->nullable()->index('idx_proyek_unit_id_armada');
            $table->char('id_armada_vendor', 36)->nullable()->index('idx_proyek_unit_id_armada_vendor');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyek_unit');
    }
};
