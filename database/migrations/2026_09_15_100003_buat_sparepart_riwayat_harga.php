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
        Schema::create('sparepart_riwayat_harga', function (Blueprint $table) {
            $table->char('id_riwayat', 36)->primary();
            $table->char('id_sparepart', 36)->index();
            $table->decimal('harga_lama', 15, 2)->nullable();
            $table->decimal('harga_baru', 15, 2);
            $table->string('sumber', 30);
            $table->text('keterangan')->nullable();
            MigrationHelper::auditColumns($table);
        });

        $this->backfillHargaAwal();
    }

    private function backfillHargaAwal(): void
    {
        $now = now();

        DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->orderBy('id_sparepart')
            ->select(['id_sparepart', 'harga_standar', 'dibuat_pada'])
            ->chunk(500, function ($rows) use ($now) {
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = [
                        'id_riwayat'   => (string) Str::uuid(),
                        'id_sparepart' => $row->id_sparepart,
                        'harga_lama'   => null,
                        'harga_baru'   => $row->harga_standar ?? 0,
                        'sumber'       => 'migrasi',
                        'keterangan'   => null,
                        'dibuat_pada'  => $row->dibuat_pada ?? $now,
                    ];
                }
                if ($batch !== []) {
                    DB::table('sparepart_riwayat_harga')->insert($batch);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart_riwayat_harga');
    }
};
