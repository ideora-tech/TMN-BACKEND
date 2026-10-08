<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uang_jalan', function (Blueprint $table) {
            if (!Schema::hasColumn('uang_jalan', 'id_proyek')) {
                $table->char('id_proyek', 36)->nullable()->index();
            }
            if (!Schema::hasColumn('uang_jalan', 'kode_proyek')) {
                $table->string('kode_proyek', 50)->nullable();
            }
            if (!Schema::hasColumn('uang_jalan', 'nama_proyek')) {
                $table->string('nama_proyek', 200)->nullable();
            }
            if (!Schema::hasColumn('uang_jalan', 'id_penugasan')) {
                $table->char('id_penugasan', 36)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('uang_jalan', function (Blueprint $table) {
            foreach (['id_proyek', 'kode_proyek', 'nama_proyek', 'id_penugasan'] as $nama) {
                if (Schema::hasColumn('uang_jalan', $nama)) {
                    if (in_array($nama, ['id_proyek', 'id_penugasan'], true)) {
                        $table->dropIndex(["uang_jalan_{$nama}_index"]);
                    }
                    $table->dropColumn($nama);
                }
            }
        });
    }
};
