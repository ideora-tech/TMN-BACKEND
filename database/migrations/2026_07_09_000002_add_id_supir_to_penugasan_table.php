<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penugasan', function (Blueprint $table) {
            $table->string('id_supir', 36)->nullable()->after('id_armada');
        });
    }

    public function down(): void
    {
        Schema::table('penugasan', function (Blueprint $table) {
            $table->dropColumn('id_supir');
        });
    }
};
