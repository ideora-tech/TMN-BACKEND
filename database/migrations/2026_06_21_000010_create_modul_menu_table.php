<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('modul_menu', function (Blueprint $table) {
            $table->string('kode_modul', 50);
            $table->char('id_menu', 36);
            $table->primary(['kode_modul', 'id_menu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modul_menu');
    }
};
