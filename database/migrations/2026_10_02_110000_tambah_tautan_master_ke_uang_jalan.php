<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $kolom = ['id_supir', 'id_supir_vendor', 'id_armada', 'id_armada_vendor', 'id_vendor', 'id_rute'];

    public function up(): void
    {
        Schema::table('uang_jalan', function (Blueprint $table) {
            foreach ($this->kolom as $nama) {
                if (!Schema::hasColumn('uang_jalan', $nama)) {
                    $table->char($nama, 36)->nullable()->index();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('uang_jalan', function (Blueprint $table) {
            foreach ($this->kolom as $nama) {
                if (Schema::hasColumn('uang_jalan', $nama)) {
                    $table->dropIndex([$nama]);
                    $table->dropColumn($nama);
                }
            }
        });
    }
};
