<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pengajuan_pengeluaran')
            ->where('status', 'dicek')
            ->update(['status' => 'siap_transfer']);
    }

    public function down(): void
    {
        DB::table('pengajuan_pengeluaran')
            ->where('status', 'siap_transfer')
            ->update(['status' => 'dicek']);
    }
};
