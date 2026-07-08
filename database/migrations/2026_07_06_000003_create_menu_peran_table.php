<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('menu_peran', function (Blueprint $table) {
            $table->char('id_menu', 36);
            $table->string('kode_peran', 50);
            $table->primary(['id_menu', 'kode_peran']);
            $table->foreign('id_menu')->references('id_menu')->on('menu')->onDelete('cascade');
        });

        Schema::table('menu', function (Blueprint $table) {
            $table->dropColumn('kode_peran');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_peran');

        Schema::table('menu', function (Blueprint $table) {
            $table->json('kode_peran')->nullable()->after('icon');
        });
    }
};
