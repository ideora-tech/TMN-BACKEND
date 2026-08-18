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
        if (!Schema::hasColumn('proyek', 'tipe_harga')) {
            Schema::table('proyek', function (Blueprint $table) {
                $table->string('tipe_harga', 20)->default('per_rit')->after('status');
            });
        }

        if (!Schema::hasColumn('penawaran', 'tipe_harga')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->string('tipe_harga', 20)->default('per_rit')->after('status');
            });
        }

        if (!Schema::hasColumn('penawaran', 'id_penawaran_induk')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->char('id_penawaran_induk', 36)->nullable()->after('id_proyek');
            });
        }

        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->char('id_jenis_kendaraan', 36)->nullable()->change();
        });

        if (!Schema::hasColumn('proyek_rute', 'uang_jalan')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->decimal('uang_jalan', 15, 2)->nullable()->after('estimasi_ritase');
            });
        }
        if (!Schema::hasColumn('proyek_rute', 'estimasi_tol')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->decimal('estimasi_tol', 15, 2)->nullable()->after('uang_jalan');
            });
        }
        if (!Schema::hasColumn('proyek_rute', 'estimasi_bbm')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->decimal('estimasi_bbm', 15, 2)->nullable()->after('estimasi_tol');
            });
        }
        if (!Schema::hasColumn('proyek_rute', 'estimasi_biaya_lain')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->decimal('estimasi_biaya_lain', 15, 2)->nullable()->after('estimasi_bbm');
            });
        }

        if (Schema::hasColumn('proyek_rute', 'id_tarif_rute')) {
            DB::table('proyek_rute')
                ->whereNotNull('id_tarif_rute')
                ->orderBy('id_proyek_rute')
                ->get(['id_proyek_rute', 'id_tarif_rute', 'harga_penawaran'])
                ->each(function ($baris) {
                    $tarif = DB::table('tarif_rute')
                        ->where('id_tarif_rute', $baris->id_tarif_rute)
                        ->whereNull('dihapus_pada')
                        ->first();
                    if ($tarif === null) {
                        return;
                    }

                    $update = [
                        'uang_jalan'          => $tarif->estimasi_uang_jalan,
                        'estimasi_tol'        => $tarif->estimasi_tol,
                        'estimasi_bbm'        => $tarif->estimasi_bbm,
                        'estimasi_biaya_lain' => $tarif->estimasi_biaya_lain,
                    ];
                    if ($baris->harga_penawaran === null) {
                        $update['harga_penawaran'] = $tarif->harga;
                    }

                    DB::table('proyek_rute')->where('id_proyek_rute', $baris->id_proyek_rute)->update($update);
                });

            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->dropColumn('id_tarif_rute');
            });
        }

        if (Schema::hasColumn('penawaran_item', 'id_tarif_rute')) {
            Schema::table('penawaran_item', function (Blueprint $table) {
                $table->dropColumn('id_tarif_rute');
            });
        }

        Schema::table('penawaran_item', function (Blueprint $table) {
            $table->decimal('harga_satuan', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('proyek_rute', function (Blueprint $table) {
            $table->char('id_jenis_kendaraan', 36)->nullable(false)->change();
        });

        Schema::table('penawaran_item', function (Blueprint $table) {
            $table->decimal('harga_satuan', 15, 2)->nullable(false)->change();
        });

        if (!Schema::hasColumn('penawaran_item', 'id_tarif_rute')) {
            Schema::table('penawaran_item', function (Blueprint $table) {
                $table->char('id_tarif_rute', 36)->nullable()->after('id_jenis_kendaraan');
            });
        }

        if (!Schema::hasColumn('proyek_rute', 'id_tarif_rute')) {
            Schema::table('proyek_rute', function (Blueprint $table) {
                $table->char('id_tarif_rute', 36)->nullable()->after('id_jenis_kendaraan');
            });
        }

        Schema::table('proyek_rute', function (Blueprint $table) {
            foreach (['estimasi_biaya_lain', 'estimasi_bbm', 'estimasi_tol', 'uang_jalan'] as $kolom) {
                if (Schema::hasColumn('proyek_rute', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });

        if (Schema::hasColumn('penawaran', 'id_penawaran_induk')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dropColumn('id_penawaran_induk');
            });
        }
        if (Schema::hasColumn('penawaran', 'tipe_harga')) {
            Schema::table('penawaran', function (Blueprint $table) {
                $table->dropColumn('tipe_harga');
            });
        }
        if (Schema::hasColumn('proyek', 'tipe_harga')) {
            Schema::table('proyek', function (Blueprint $table) {
                $table->dropColumn('tipe_harga');
            });
        }
    }
};
