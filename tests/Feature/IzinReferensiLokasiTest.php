<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinReferensiLokasiTest extends TestCase
{
    use RefreshDatabase;

    private function idMenu(string $path): string
    {
        $id = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $id, 'nama_menu' => trim($path, '/'), 'path' => $path,
                'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        return (string) $id;
    }

    private function setIzin(string $kodePeran, string $path, string $aksi, int $diizinkan): void
    {
        $idMenu = $this->idMenu($path);
        DB::table('izin_peran')->where('id_menu', $idMenu)->where('kode_peran', $kodePeran)->where('aksi', $aksi)->delete();
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => $aksi, 'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function tutupSemuaIzinLihat(): void
    {
        foreach (['/lokasi', '/rute', '/penawaran', '/project'] as $path) {
            $this->setIzin('DISPATCHER', $path, 'lihat', 0);
        }
        $this->setIzin('DISPATCHER', '/lokasi', 'tambah', 0);
        $this->setIzin('DISPATCHER', '/lokasi', 'hapus', 0);
    }

    private function actingAsDispatcher(): Pengguna
    {
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'DISPATCHER', 'username' => 'dsp_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function buatLokasi(): string
    {
        $id = (string) Str::uuid();
        DB::table('lokasi')->insert([
            'id_lokasi' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_lokasi' => 'Gudang Cikarang',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public static function menuPemakaiLokasi(): array
    {
        return [['/rute'], ['/penawaran'], ['/project']];
    }

    /** @dataProvider menuPemakaiLokasi */
    public function test_izin_menu_pemakai_boleh_baca_lokasi_tanpa_izin_master(string $path): void
    {
        $this->tutupSemuaIzinLihat();
        $this->setIzin('DISPATCHER', $path, 'lihat', 1);
        $idLokasi = $this->buatLokasi();
        $this->actingAsDispatcher();

        $this->getJson('/api/lokasi?page=1&limit=200')
            ->assertStatus(200)
            ->assertJsonFragment(['id_lokasi' => $idLokasi]);
        $this->getJson("/api/lokasi/{$idLokasi}")->assertStatus(200);
    }

    public function test_tanpa_izin_menu_manapun_baca_lokasi_tetap_403(): void
    {
        $this->tutupSemuaIzinLihat();
        $this->actingAsDispatcher();

        $this->getJson('/api/lokasi')->assertStatus(403);
    }

    public function test_tulis_lokasi_tetap_butuh_izin_master_lokasi(): void
    {
        $this->tutupSemuaIzinLihat();
        $this->setIzin('DISPATCHER', '/penawaran', 'lihat', 1);
        $this->setIzin('DISPATCHER', '/penawaran', 'tambah', 1);
        $this->setIzin('DISPATCHER', '/penawaran', 'hapus', 1);
        $idLokasi = $this->buatLokasi();
        $this->actingAsDispatcher();

        $this->postJson('/api/lokasi', ['nama_lokasi' => 'Pelabuhan Baru'])->assertStatus(403);
        $this->deleteJson("/api/lokasi/{$idLokasi}")->assertStatus(403);
    }
}
