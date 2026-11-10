<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akun login (terutama supir mobile) tidak selalu punya email. Login memakai username,
 * email hanya pelengkap. Index unik email tetap ada: MySQL membolehkan banyak NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengguna', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pengguna', function (Blueprint $table) {
            $table->string('email', 150)->nullable(false)->change();
        });
    }
};
