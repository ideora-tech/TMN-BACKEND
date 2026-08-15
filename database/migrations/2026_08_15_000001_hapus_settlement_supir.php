<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $idMenuSettlement = 'm0000001-0000-4000-8000-000000000033';

    public function up(): void
    {
        $kolom = array_values(array_filter(
            ['status_settlement', 'settlement_pada', 'settlement_oleh', 'catatan_settlement'],
            fn (string $k) => Schema::hasColumn('trip', $k)
        ));

        if ($kolom !== []) {
            Schema::table('trip', function (Blueprint $table) use ($kolom) {
                $table->dropColumn($kolom);
            });
        }

        DB::table('izin_peran')->where('id_menu', $this->idMenuSettlement)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenuSettlement)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuSettlement)->delete();
    }

    public function down(): void
    {
    }
};
