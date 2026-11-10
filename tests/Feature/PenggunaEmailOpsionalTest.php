<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenggunaEmailOpsionalTest extends TestCase
{
    use RefreshDatabase;

    private function buat(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pengguna', array_merge([
            'username' => 'driver' . random_int(1000, 9999), 'password' => 'Password123!', 'kode_peran' => 'SUPIR',
        ], $override));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('SUPERADMIN');
    }

    public function test_pengguna_dibuat_tanpa_email(): void
    {
        $res = $this->buat(['username' => 'Driver002'])->assertStatus(201);

        $this->assertNull($res->json('data.email'));
        $this->assertNull(DB::table('pengguna')->where('username', 'Driver002')->value('email'));
    }

    public function test_email_kosong_atau_spasi_disimpan_sebagai_null(): void
    {
        $this->buat(['username' => 'drv_a', 'email' => ''])->assertStatus(201);
        $this->buat(['username' => 'drv_b', 'email' => null])->assertStatus(201);
        $this->buat(['username' => 'drv_c', 'email' => '   '])->assertStatus(201);

        $this->assertSame(3, DB::table('pengguna')->whereIn('username', ['drv_a', 'drv_b', 'drv_c'])->whereNull('email')->count());
    }

    public function test_banyak_akun_tanpa_email_tidak_bentrok_di_index_unik(): void
    {
        $this->buat(['username' => 'drv_1'])->assertStatus(201);
        $this->buat(['username' => 'drv_2'])->assertStatus(201);
        $this->buat(['username' => 'drv_3'])->assertStatus(201);

        $this->assertSame(3, DB::table('pengguna')->whereIn('username', ['drv_1', 'drv_2', 'drv_3'])->count());
    }

    public function test_email_yang_diisi_tetap_harus_valid_dan_unik(): void
    {
        $this->buat(['username' => 'ada_email', 'email' => 'ada@sulita.co.id'])->assertStatus(201);

        $this->buat(['username' => 'bentrok', 'email' => 'ada@sulita.co.id'])->assertStatus(409);
        $this->buat(['username' => 'salah_format', 'email' => 'bukan-email'])->assertStatus(422);
    }

    public function test_username_tetap_wajib(): void
    {
        $this->postJson('/api/pengguna', ['password' => 'Password123!', 'kode_peran' => 'SUPIR'])->assertStatus(422);
    }

    public function test_update_bisa_mengosongkan_email(): void
    {
        $id = $this->buat(['username' => 'punya_email', 'email' => 'lama@sulita.co.id'])->assertStatus(201)->json('data.id_pengguna');

        $this->putJson("/api/pengguna/{$id}", ['email' => ''])->assertStatus(200);

        $this->assertNull(DB::table('pengguna')->where('id_pengguna', $id)->value('email'));
    }

    public function test_update_email_ke_milik_akun_lain_tetap_ditolak(): void
    {
        $this->buat(['username' => 'pemilik', 'email' => 'milik@sulita.co.id'])->assertStatus(201);
        $id = $this->buat(['username' => 'lain'])->assertStatus(201)->json('data.id_pengguna');

        $this->putJson("/api/pengguna/{$id}", ['email' => 'milik@sulita.co.id'])->assertStatus(409);
    }

    public function test_akun_tanpa_email_bisa_login_dengan_username(): void
    {
        $this->buat(['username' => 'Driver002', 'password' => 'Rahasia123!'])->assertStatus(201);

        $this->postJson('/api/auth/login', ['username' => 'Driver002', 'password' => 'Rahasia123!'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
