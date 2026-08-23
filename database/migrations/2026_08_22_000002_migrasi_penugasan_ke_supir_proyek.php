<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Data migration satu arah: salin pendaftaran supir-proyek yang selama
     * ini tersirat dari penugasan (sumber internal, status pending/aktif)
     * ke tabel supir_proyek yang eksplisit. Ditulis lewat query builder PHP
     * (bukan raw SQL UUID()) supaya jalan juga di SQLite (test env) —
     * fungsi UUID() tidak tersedia di driver itu.
     */
    public function up(): void
    {
        if (!Schema::hasTable('penugasan') || !Schema::hasTable('proyek') || !Schema::hasTable('supir_proyek')) {
            return;
        }

        $now = now();

        $kombinasi = DB::table('penugasan as p')
            ->join('proyek as pr', 'pr.id_proyek', '=', 'p.id_proyek')
            ->whereNull('p.dihapus_pada')
            ->where('p.sumber', 'internal')
            ->whereNotNull('p.id_supir')
            ->whereIn('p.status', ['pending', 'aktif'])
            ->select('pr.id_perusahaan', 'p.id_proyek', 'p.id_supir')
            ->distinct()
            ->get();

        foreach ($kombinasi as $baris) {
            $sudahAda = DB::table('supir_proyek')
                ->whereNull('dihapus_pada')
                ->where('id_proyek', $baris->id_proyek)
                ->where('id_supir', $baris->id_supir)
                ->exists();

            if ($sudahAda) {
                continue;
            }

            DB::table('supir_proyek')->insert([
                'id_supir_proyek' => (string) Str::uuid(),
                'id_perusahaan'   => $baris->id_perusahaan,
                'id_proyek'       => $baris->id_proyek,
                'id_supir'        => $baris->id_supir,
                'dibuat_pada'     => $now,
            ]);
        }
    }

    /**
     * Migration data satu arah — tidak direverse (baris yang sudah dibuat
     * user secara manual sesudahnya tidak bisa dibedakan dari hasil migrasi
     * ini tanpa penanda tambahan).
     */
    public function down(): void
    {
    }
};
