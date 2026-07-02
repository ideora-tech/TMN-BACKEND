<?php

namespace App\Helpers;

use Illuminate\Database\Schema\Blueprint;

class MigrationHelper
{
    public static function auditColumns(Blueprint $table): void
    {
        $table->dateTime('dibuat_pada')->useCurrent();
        $table->char('dibuat_oleh', 36)->nullable();
        $table->dateTime('diubah_pada')->nullable();
        $table->char('diubah_oleh', 36)->nullable();
        $table->dateTime('dihapus_pada')->nullable();
        $table->char('dihapus_oleh', 36)->nullable();
    }
}
