<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('penawaran', 'email_terkirim_ke')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->string('email_terkirim_ke', 150)->nullable()->after('alasan_ditolak_internal');
            });
        }

        if (!Schema::hasColumn('penawaran', 'email_terkirim_pada')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dateTime('email_terkirim_pada')->nullable()->after('email_terkirim_ke');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('penawaran', 'email_terkirim_pada')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dropColumn('email_terkirim_pada');
            });
        }

        if (Schema::hasColumn('penawaran', 'email_terkirim_ke')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dropColumn('email_terkirim_ke');
            });
        }
    }
};
