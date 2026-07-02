<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('log_error', function (Blueprint $table) {
            $table->char('id_log_error', 36)->primary();
            $table->string('level', 20)->default('error');
            $table->text('pesan');
            $table->longText('stack_trace')->nullable();
            $table->string('metode_http', 10)->nullable();
            $table->string('jalur', 500)->nullable();
            $table->integer('kode_status')->nullable();
            $table->char('id_pengguna', 36)->nullable();
            $table->dateTime('dibuat_pada')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_error');
    }
};
