<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->tinyInteger('tampil_sidebar')->default(1);
        });

        DB::table('menu')->where('path', '/pembelian-sparepart')->update(['tampil_sidebar' => 0]);
    }

    public function down(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->dropColumn('tampil_sidebar');
        });
    }
};
