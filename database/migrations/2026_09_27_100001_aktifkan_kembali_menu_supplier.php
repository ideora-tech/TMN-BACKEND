<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuSupplier = 'm0000001-0000-4000-8000-000000000085';

    public function up(): void
    {
        DB::table('menu')
            ->where('id_menu', $this->idMenuSupplier)
            ->whereNull('dihapus_pada')
            ->update(['aktif' => 1]);
    }

    public function down(): void
    {
        DB::table('menu')
            ->where('id_menu', $this->idMenuSupplier)
            ->update(['aktif' => 0]);
    }
};
