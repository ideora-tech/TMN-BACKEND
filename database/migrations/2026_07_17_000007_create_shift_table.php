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
        Schema::create('shift', function (Blueprint $table) {
            $table->char('id_shift', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 100);
            $table->time('jam_mulai');
            $table->time('jam_selesai'); // < jam_mulai berarti berakhir hari berikutnya
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift');
    }
};
