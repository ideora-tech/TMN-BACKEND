<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faktur_pajak', function (Blueprint $table) {
            $table->char('id_faktur_pajak', 36)->primary();
            $table->char('id_faktur', 36)->index();
            $table->string('nama', 100);
            $table->decimal('persen', 5, 2);
            $table->unsignedSmallInteger('urutan')->default(1);
            MigrationHelper::auditColumns($table);
        });

        $faktur = DB::table('faktur')
            ->whereNull('dihapus_pada')
            ->whereNotNull('persen_pajak')
            ->get(['id_faktur', 'nama_pajak', 'persen_pajak', 'dibuat_pada']);

        foreach ($faktur as $row) {
            DB::table('faktur_pajak')->insert([
                'id_faktur_pajak' => (string) Str::uuid(),
                'id_faktur'       => $row->id_faktur,
                'nama'            => $row->nama_pajak ?? '',
                'persen'          => $row->persen_pajak,
                'urutan'          => 1,
                'dibuat_pada'     => $row->dibuat_pada ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('faktur_pajak');
    }
};
