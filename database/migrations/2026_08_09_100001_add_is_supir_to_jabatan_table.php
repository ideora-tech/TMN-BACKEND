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
            $table->tinyInteger('is_supir')->default(0)->after('nama_jabatan');
        });
    }

    public function down(): void
    {
        Schema::table('jabatan', function (Blueprint $table) {
            $table->dropColumn('is_supir');
        });
    }
};
