<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IzinReferensiShiftTest extends TestCase
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

    private function tutupSemuaIzin(): void
    {
        foreach (['/shift', '/jadwal-shift-supir'] as $path) {
            foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
                $this->setIzin('DISPATCHER', $path, $aksi, 0);
            }
        }
    }

    private function actingAsDispatcher(): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'DISPATCHER', 'username' => 'dsp_' . Str::random(6),
            'email' => Str::random(6) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function buatShift(): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Shift Pagi',
            'jam_mulai' => '06:00:00', 'jam_selesai' => '14:00:00', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_izin_papan_shift_boleh_baca_shift_tanpa_izin_master_shift(): void
    {
        $this->tutupSemuaIzin();
        $this->setIzin('DISPATCHER', '/jadwal-shift-supir', 'lihat', 1);
        $idShift = $this->buatShift();
        $this->actingAsDispatcher();

        $this->getJson('/api/shift?page=1&limit=100')
            ->assertStatus(200)
            ->assertJsonFragment(['id_shift' => $idShift]);
        $this->getJson("/api/shift/{$idShift}")->assertStatus(200);
    }

    public function test_tanpa_izin_menu_manapun_baca_shift_tetap_403(): void
    {
        $this->tutupSemuaIzin();
        $this->actingAsDispatcher();

        $this->getJson('/api/shift')->assertStatus(403);
    }

    public function test_tulis_shift_tetap_butuh_izin_master_shift(): void
    {
        $this->tutupSemuaIzin();
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
            $this->setIzin('DISPATCHER', '/jadwal-shift-supir', $aksi, 1);
        }
        $idShift = $this->buatShift();
        $this->actingAsDispatcher();

        $this->postJson('/api/shift', ['nama' => 'Shift Malam', 'jam_mulai' => '22:00', 'jam_selesai' => '06:00'])->assertStatus(403);
        $this->putJson("/api/shift/{$idShift}", ['nama' => 'Shift Pagi Baru'])->assertStatus(403);
        $this->deleteJson("/api/shift/{$idShift}")->assertStatus(403);
    }
}
