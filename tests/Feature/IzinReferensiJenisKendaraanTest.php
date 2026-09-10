<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinReferensiJenisKendaraanTest extends TestCase
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

    private function actingAsBod(): Pengguna
    {
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'BOD', 'username' => 'bod_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    public function test_peran_dengan_izin_perawatan_boleh_baca_jenis_kendaraan_meski_tanpa_izin_master(): void
    {
        $this->setIzin('BOD', '/perawatan-armada', 'lihat', 1);
        $this->setIzin('BOD', '/jenis-kendaraan', 'lihat', 0);
        $this->actingAsBod();

        $this->getJson('/api/jenis-kendaraan')->assertStatus(200);
    }

    public function test_tanpa_izin_modul_manapun_baca_jenis_kendaraan_tetap_403(): void
    {
        $this->setIzin('BOD', '/perawatan-armada', 'lihat', 0);
        $this->setIzin('BOD', '/jenis-kendaraan', 'lihat', 0);
        $this->actingAsBod();

        $this->getJson('/api/jenis-kendaraan')->assertStatus(403);
    }

    public function test_tulis_jenis_kendaraan_tetap_butuh_izin_master(): void
    {
        $this->setIzin('BOD', '/perawatan-armada', 'lihat', 1);
        $this->setIzin('BOD', '/perawatan-armada', 'tambah', 1);
        $this->setIzin('BOD', '/jenis-kendaraan', 'tambah', 0);
        $this->actingAsBod();

        $this->postJson('/api/jenis-kendaraan', [
            'kode_jenis' => 'JK-' . Str::random(4),
            'nama_jenis' => 'Percobaan',
        ])->assertStatus(403);
    }
}
