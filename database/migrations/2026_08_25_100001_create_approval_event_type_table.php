<?php

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_event_type', function (Blueprint $table) {
            $table->char('id_event_type', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('kode', 50);
            $table->string('nama', 100);
            $table->enum('mode_resolusi', ['pinned', 'relatif']);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(['id_perusahaan', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_event_type');
    }
};
