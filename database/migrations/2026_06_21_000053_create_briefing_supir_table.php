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
        Schema::create('briefing_supir', function (Blueprint $table) {
            $table->char('id_briefing', 36)->primary();
            $table->char('id_penugasan', 36);
            $table->text('catatan_rute')->nullable();
            $table->text('catatan_keselamatan')->nullable();
            $table->char('id_dibriefing_oleh', 36)->nullable();
            $table->dateTime('waktu_briefing')->nullable();
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefing_supir');
    }
};
