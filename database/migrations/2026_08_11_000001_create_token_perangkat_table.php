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
        Schema::create('token_perangkat', function (Blueprint $table) {
            $table->char('id_token_perangkat', 36)->primary();
            $table->char('id_pengguna', 36)->index();
            $table->string('token', 255)->unique();
            $table->string('platform', 20)->default('android');
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_perangkat');
    }
};
