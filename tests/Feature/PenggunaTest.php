<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenggunaTest extends TestCase
{
    use RefreshDatabase;

    private function makePengguna(string $kodePeran = 'SUPIR'): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => 'user_' . Str::random(8),
            'email'         => Str::random(10) . '@test.id',
            'kata_sandi'    => Hash::make('Password123!'),
            'aktif'         => 1,
        ]);
        return $id;
    }

    public function test_show_mengembalikan_kode_peran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePengguna('SUPIR');

        $this->getJson("/api/pengguna/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.kode_peran', 'SUPIR');
    }

    public function test_update_kode_peran_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePengguna('SUPIR');

        $this->putJson("/api/pengguna/{$id}", ['kode_peran' => 'MANAGER'])
            ->assertStatus(200)
            ->assertJsonPath('data.kode_peran', 'MANAGER');

        $this->assertSame('MANAGER', DB::table('pengguna')->where('id_pengguna', $id)->value('kode_peran'));

        $this->putJson("/api/pengguna/{$id}", ['username' => 'username_baru'])
            ->assertStatus(200)
            ->assertJsonPath('data.kode_peran', 'MANAGER');
    }

    public function test_update_password_mengganti_kata_sandi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $id = $this->makePengguna();

        $this->putJson("/api/pengguna/{$id}", ['password' => 'SandiBaru123!'])
            ->assertStatus(200);

        $hash = (string) DB::table('pengguna')->where('id_pengguna', $id)->value('kata_sandi');
        $this->assertTrue(Hash::check('SandiBaru123!', $hash));

        $this->putJson("/api/pengguna/{$id}", ['password' => 'pendek'])
            ->assertStatus(422);
    }
}
