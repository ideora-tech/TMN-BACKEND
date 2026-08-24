<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->char('id_jabatan_induk', 36)->nullable()->after('id_departemen');
            $table->index('id_jabatan_induk');
        });
    }

    public function down(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->dropIndex(['id_jabatan_induk']);
            $table->dropColumn('id_jabatan_induk');
        });
    }
};
