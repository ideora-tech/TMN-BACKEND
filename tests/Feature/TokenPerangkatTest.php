<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TokenPerangkatTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_token_membuat_baris(): void
    {
        $user = $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc', 'platform' => 'android'])
            ->assertStatus(200);

        $baris = DB::table('token_perangkat')->where('token', 'fcm-abc')->first();
        $this->assertNotNull($baris);
        $this->assertSame($user->id_pengguna, $baris->id_pengguna);
        $this->assertSame('android', $baris->platform);
    }

    public function test_daftar_token_sama_dua_kali_tetap_satu_baris(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);
        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);

        $this->assertSame(1, DB::table('token_perangkat')->where('token', 'fcm-abc')->count());
    }

    public function test_token_pindah_kepemilikan_saat_pengguna_lain_mendaftar(): void
    {
        $a = $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);

        $b = $this->actingAsRole('ADMIN');
        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);

        $this->assertSame(1, DB::table('token_perangkat')->where('token', 'fcm-abc')->count());
        $baris = DB::table('token_perangkat')->where('token', 'fcm-abc')->first();
        $this->assertSame($b->id_pengguna, $baris->id_pengguna);
        $this->assertNotSame($a->id_pengguna, $baris->id_pengguna);
    }

    public function test_hapus_token_soft_delete_dan_daftar_ulang_menghidupkan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);
        $this->deleteJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);

        $this->assertNotNull(DB::table('token_perangkat')->where('token', 'fcm-abc')->value('dihapus_pada'));

        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(200);
        $this->assertNull(DB::table('token_perangkat')->where('token', 'fcm-abc')->value('dihapus_pada'));
        $this->assertSame(1, DB::table('token_perangkat')->where('token', 'fcm-abc')->count());
    }

    public function test_tanpa_login_401(): void
    {
        $this->postJson('/api/token-perangkat', ['token' => 'fcm-abc'])->assertStatus(401);
    }
}
