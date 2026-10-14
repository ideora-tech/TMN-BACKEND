<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('proyek_rute', 'fee_supir_jenjang')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->json('fee_supir_jenjang')->nullable();
            });
        }

        if (!Schema::hasColumn('proyek_rute', 'fee_supir')) {
            return;
        }

        DB::table('proyek_rute')
            ->whereNotNull('fee_supir')
            ->where('fee_supir', '>', 0)
            ->orderBy('id_proyek_rute')
            ->select(['id_proyek_rute', 'fee_supir'])
            ->chunk(500, function ($baris) {
                foreach ($baris as $r) {
                    DB::table('proyek_rute')
                        ->where('id_proyek_rute', $r->id_proyek_rute)
                        ->update(['fee_supir_jenjang' => json_encode([['sampai' => null, 'nominal' => round((float) $r->fee_supir, 2)]])]);
                }
            });

        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->dropColumn('fee_supir');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('proyek_rute', 'fee_supir')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->decimal('fee_supir', 15, 2)->nullable();
            });
        }

        if (!Schema::hasColumn('proyek_rute', 'fee_supir_jenjang')) {
            return;
        }

        DB::table('proyek_rute')
            ->whereNotNull('fee_supir_jenjang')
            ->orderBy('id_proyek_rute')
            ->select(['id_proyek_rute', 'fee_supir_jenjang'])
            ->chunk(500, function ($baris) {
                foreach ($baris as $r) {
                    $jenjang = json_decode((string) $r->fee_supir_jenjang, true);
                    $nominal = is_array($jenjang) && isset($jenjang[0]['nominal']) ? (float) $jenjang[0]['nominal'] : null;
                    DB::table('proyek_rute')->where('id_proyek_rute', $r->id_proyek_rute)->update(['fee_supir' => $nominal]);
                }
            });

        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->dropColumn('fee_supir_jenjang');
        });
    }
};
