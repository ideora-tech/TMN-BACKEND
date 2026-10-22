<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABEL = ['armada_vendor', 'supir_vendor'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasColumn($tabel, 'id_kontrak_asal')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->char('id_kontrak_asal', 36)->nullable()->after('id_kontrak_vendor')->index();
                });
            }

            $kunci = $tabel === 'armada_vendor' ? 'id_armada_vendor' : 'id_supir_vendor';
            $baris = DB::table("{$tabel} as t")
                ->join('kontrak_vendor as k', 'k.id_kontrak_vendor', '=', 't.id_kontrak_vendor')
                ->whereNull('t.id_kontrak_asal')
                ->whereNotNull('k.dibuat_pada')
                ->whereNotNull('t.dibuat_pada')
                ->get(["t.{$kunci} as id", 't.id_kontrak_vendor', 't.dibuat_pada as dibuat', 'k.dibuat_pada as kontrak_dibuat']);

            foreach ($baris as $satu) {
                $selisih = strtotime((string) $satu->dibuat) - strtotime((string) $satu->kontrak_dibuat);
                if ($selisih >= 0 && $selisih <= 120) {
                    DB::table($tabel)->where($kunci, $satu->id)->update(['id_kontrak_asal' => $satu->id_kontrak_vendor]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (Schema::hasColumn($tabel, 'id_kontrak_asal')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->dropIndex(['id_kontrak_asal']);
                    $table->dropColumn('id_kontrak_asal');
                });
            }
        }
    }
};
