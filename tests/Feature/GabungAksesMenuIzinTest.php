<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GabungAksesMenuIzinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['MANAGER', 'KEUANGAN'] as $kode) {
            DB::table('peran')->insertOrIgnore([
                'id_peran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'kode_peran' => $kode, 'nama_peran' => $kode, 'dibuat_pada' => now(),
            ]);
        }
    }

    private function buatMenu(?string $idInduk, ?string $path, string $nama, int $urutan = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('menu')->insert([
            'id_menu' => $id, 'nama_menu' => $nama, 'path' => $path,
            'id_menu_induk' => $idInduk, 'icon' => 'truck', 'urutan' => $urutan,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function beriIzinLihat(string $idMenu, string $kodePeran, int $diizinkan = 1, ?string $idPerusahaan = null): void
    {
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => $idPerusahaan,
            'kode_peran' => $kodePeran, 'id_menu' => $idMenu, 'aksi' => 'lihat',
            'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function namaMenuTree(): array
    {
        $res = $this->getJson('/api/menu/tree')->assertStatus(200);
        $ambil = function (array $nodes) use (&$ambil): array {
            $hasil = [];
            foreach ($nodes as $n) {
                $hasil[] = $n['nama_menu'];
                $hasil = array_merge($hasil, $ambil($n['children'] ?? []));
            }
            return $hasil;
        };
        return $ambil($res->json('data'));
    }

    public function test_tree_hanya_memuat_menu_dengan_izin_lihat_dan_grup_induknya(): void
    {
        $grup  = $this->buatMenu(null, null, 'Grup Uji');
        $anak1 = $this->buatMenu($grup, '/uji-satu', 'Uji Satu');
        $anak2 = $this->buatMenu($grup, '/uji-dua', 'Uji Dua', 2);
        $this->buatMenu($grup, '/uji-tiga', 'Uji Tiga', 3);
        $this->beriIzinLihat($anak1, 'MANAGER');
        $this->beriIzinLihat($anak2, 'MANAGER');

        $this->actingAsRole('MANAGER');
        $nama = $this->namaMenuTree();
        $this->assertContains('Grup Uji', $nama);
        $this->assertContains('Uji Satu', $nama);
        $this->assertContains('Uji Dua', $nama);
        $this->assertNotContains('Uji Tiga', $nama);
    }

    public function test_grup_tanpa_anak_tampil_ikut_hilang(): void
    {
        $grup = $this->buatMenu(null, null, 'Grup Kosong');
        $this->buatMenu($grup, '/uji-empat', 'Uji Empat');

        $this->actingAsRole('MANAGER');
        $nama = $this->namaMenuTree();
        $this->assertNotContains('Grup Kosong', $nama);
        $this->assertNotContains('Uji Empat', $nama);
    }

    public function test_revoke_per_perusahaan_menang_atas_global(): void
    {
        $menu = $this->buatMenu(null, '/uji-lima', 'Uji Lima');
        $this->beriIzinLihat($menu, 'MANAGER', 1, null);
        $this->beriIzinLihat($menu, 'MANAGER', 0, self::PERUSAHAAN_ID);

        $this->actingAsRole('MANAGER');
        $this->assertNotContains('Uji Lima', $this->namaMenuTree());
    }

    public function test_superadmin_melihat_semua_menu_aktif_tanpa_baris_izin(): void
    {
        $this->buatMenu(null, '/uji-enam', 'Uji Enam');

        $this->actingAsRole('SUPERADMIN');
        $this->assertContains('Uji Enam', $this->namaMenuTree());
    }

    public function test_grup_bersarang_dua_tingkat_tampil_bila_ada_izin(): void
    {
        $grupA = $this->buatMenu(null, null, 'Grup A');
        $grupB = $this->buatMenu($grupA, null, 'Grup B');
        $anak  = $this->buatMenu($grupB, '/uji-bersarang', 'Uji Bersarang');
        $this->beriIzinLihat($anak, 'MANAGER');

        $this->actingAsRole('MANAGER');
        $nama = $this->namaMenuTree();
        $this->assertContains('Grup A', $nama);
        $this->assertContains('Grup B', $nama);
        $this->assertContains('Uji Bersarang', $nama);
    }

    public function test_grup_bersarang_dua_tingkat_hilang_tanpa_izin(): void
    {
        $grupA = $this->buatMenu(null, null, 'Grup A Kosong');
        $grupB = $this->buatMenu($grupA, null, 'Grup B Kosong');
        $this->buatMenu($grupB, '/uji-bersarang-kosong', 'Uji Bersarang Kosong');

        $this->actingAsRole('MANAGER');
        $nama = $this->namaMenuTree();
        $this->assertNotContains('Grup A Kosong', $nama);
        $this->assertNotContains('Grup B Kosong', $nama);
        $this->assertNotContains('Uji Bersarang Kosong', $nama);
    }

    public function test_endpoint_akses_peran_sudah_hilang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/menu/akses-peran')->assertStatus(404);
        $this->putJson('/api/menu/akses-peran/MANAGER', ['id_menu' => []])->assertStatus(404);
    }

    public function test_migrasi_materialisasi_menyalin_menu_peran_tanpa_menimpa_revoke(): void
    {
        $terbuka  = $this->buatMenu(null, '/uji-terbuka', 'Uji Terbuka');
        $terbatas = $this->buatMenu(null, '/uji-terbatas', 'Uji Terbatas');
        $revoked  = $this->buatMenu(null, '/uji-revoked', 'Uji Revoked');
        DB::table('menu_peran')->insert([
            ['id_menu' => $terbatas, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $revoked, 'kode_peran' => 'MANAGER'],
        ]);
        $this->beriIzinLihat($revoked, 'MANAGER', 0, null);

        $migration = require database_path('migrations/2026_08_16_110001_materialisasi_izin_lihat_dari_menu_peran.php');
        $migration->up();

        $izin = fn (string $idMenu, string $kode) => DB::table('izin_peran')
            ->where('id_menu', $idMenu)->whereRaw('UPPER(kode_peran) = ?', [$kode])
            ->where('aksi', 'lihat')->whereNull('id_perusahaan')->whereNull('dihapus_pada')->get();

        $this->assertSame(1, (int) $izin($terbuka, 'MANAGER')->first()->diizinkan);
        $this->assertSame(1, (int) $izin($terbuka, 'KEUANGAN')->first()->diizinkan);
        $this->assertSame(1, (int) $izin($terbatas, 'MANAGER')->first()->diizinkan);
        $this->assertCount(0, $izin($terbatas, 'KEUANGAN'));
        $this->assertCount(1, $izin($revoked, 'MANAGER'));
        $this->assertSame(0, (int) $izin($revoked, 'MANAGER')->first()->diizinkan);
        $this->assertCount(0, $izin($terbuka, 'SUPERADMIN'));

        $migration->up();
        $this->assertCount(1, $izin($terbuka, 'MANAGER'));
    }
}
