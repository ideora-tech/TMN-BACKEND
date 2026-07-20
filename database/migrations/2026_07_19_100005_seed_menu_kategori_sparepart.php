<?php
// database/migrations/2026_07_19_100005_seed_menu_kategori_sparepart.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idKategoriSparepart = 'm0000001-0000-4000-8000-000000000084';
    private string $idGrupPemeliharaan  = 'm0000001-0000-4000-8000-000000000080';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idKategoriSparepart, 'nama_menu' => 'Kategori Sparepart', 'path' => '/kategori-sparepart',
                'icon' => 'tag', 'id_menu_induk' => $this->idGrupPemeliharaan, 'urutan' => 9,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idKategoriSparepart)->delete();
        DB::table('menu')->where('id_menu', $this->idKategoriSparepart)->delete();
    }
};
