<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('penawaran', 'email_message_id')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->string('email_message_id', 255)->nullable()->after('email_terkirim_pada');
            });
        }

        if (!Schema::hasColumn('penawaran', 'email_gagal_pada')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dateTime('email_gagal_pada')->nullable()->after('email_message_id');
            });
        }

        if (!Schema::hasColumn('penawaran', 'email_gagal_alasan')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->text('email_gagal_alasan')->nullable()->after('email_gagal_pada');
            });
        }
    }

    public function down(): void
    {
        foreach (['email_gagal_alasan', 'email_gagal_pada', 'email_message_id'] as $kolom) {
            if (Schema::hasColumn('penawaran', $kolom)) {
                Schema::table('penawaran', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }
    }
};
