<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private string $idMenuPermintaanVendor = 'm0000001-0000-4000-8000-000000000096';
    private string $idGrupVendor           = 'm0000001-0000-4000-8000-000000000070';
    private string $idGrupPengadaan        = 'm0000001-0000-4000-8000-000000000098';
    private string $idMenuRingkasan        = 'm0000001-0000-4000-8000-000000000101';

    private array $peranLihat = ['PENGADAAN', 'SUPERADMIN', 'ADMIN', 'MANAGER'];

    public function up(): void
    {
        Schema::table('permintaan_vendor', function (Blueprint $table) {
            if (!Schema::hasColumn('permintaan_vendor', 'diproses_oleh')) {
                $table->char('diproses_oleh', 36)->nullable()->after('id_kontrak_vendor');
            }
            if (!Schema::hasColumn('permintaan_vendor', 'diproses_pada')) {
                $table->dateTime('diproses_pada')->nullable()->after('diproses_oleh');
            }
            if (!Schema::hasColumn('permintaan_vendor', 'alasan_batal')) {
                $table->text('alasan_batal')->nullable()->after('diproses_pada');
            }
        });

        $now = now();

        DB::table('menu')->where('id_menu', $this->idMenuPermintaanVendor)->update([
            'id_menu_induk' => $this->idGrupPengadaan,
            'urutan'        => 3,
        ]);

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuRingkasan, 'nama_menu' => 'Ringkasan Pengadaan', 'path' => '/pengadaan',
                'icon' => 'layers', 'id_menu_induk' => $this->idGrupPengadaan, 'urutan' => 0,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        $menuPeran = [];
        foreach ($this->peranLihat as $peran) {
            $menuPeran[] = ['id_menu' => $this->idGrupPengadaan, 'kode_peran' => $peran];
            $menuPeran[] = ['id_menu' => $this->idMenuRingkasan, 'kode_peran' => $peran];
        }
        DB::table('menu_peran')->insertOrIgnore($menuPeran);

        foreach ($this->peranLihat as $peran) {
            $ada = DB::table('izin_peran')
                ->where('id_menu', $this->idMenuRingkasan)
                ->where('kode_peran', $peran)
                ->where('aksi', 'lihat')
                ->whereNull('id_perusahaan')
                ->exists();
            if ($ada) {
                continue;
            }
            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => $peran,
                'id_menu'       => $this->idMenuRingkasan,
                'aksi'          => 'lihat',
                'diizinkan'     => 1,
                'dibuat_pada'   => $now,
            ]);
        }

        $adaUbahSales = DB::table('izin_peran')
            ->where('id_menu', $this->idMenuPermintaanVendor)
            ->where('kode_peran', 'SALES')
            ->where('aksi', 'ubah')
            ->whereNull('id_perusahaan')
            ->exists();
        if (!$adaUbahSales) {
            DB::table('izin_peran')->insert([
                'id_izin'       => (string) Str::uuid(),
                'id_perusahaan' => null,
                'kode_peran'    => 'SALES',
                'id_menu'       => $this->idMenuPermintaanVendor,
                'aksi'          => 'ubah',
                'diizinkan'     => 1,
                'dibuat_pada'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('izin_peran')
            ->where('id_menu', $this->idMenuPermintaanVendor)
            ->where('kode_peran', 'SALES')
            ->where('aksi', 'ubah')
            ->whereNull('id_perusahaan')
            ->delete();
        DB::table('izin_peran')->where('id_menu', $this->idMenuRingkasan)->delete();
        DB::table('menu_peran')->where('id_menu', $this->idMenuRingkasan)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuRingkasan)->delete();

        DB::table('menu')->where('id_menu', $this->idMenuPermintaanVendor)->update([
            'id_menu_induk' => $this->idGrupVendor,
            'urutan'        => 7,
        ]);

        Schema::table('permintaan_vendor', function (Blueprint $table) {
            foreach (['alasan_batal', 'diproses_pada', 'diproses_oleh'] as $kolom) {
                if (Schema::hasColumn('permintaan_vendor', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
