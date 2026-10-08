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
        if (Schema::hasColumn('parameter_tagihan_trip', 'add_drop_manual')) {
            return;
        }

        Schema::table('parameter_tagihan_trip', function (Blueprint $table) {
            $table->tinyInteger('add_drop_manual')->default(0);
        });

        DB::table('parameter_tagihan_trip')->where('jumlah_add_drop', '>', 0)->update(['add_drop_manual' => 1]);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('parameter_tagihan_trip', 'add_drop_manual')) {
            return;
        }

        Schema::table('parameter_tagihan_trip', function (Blueprint $table) {
            $table->dropColumn('add_drop_manual');
        });
    }
};
