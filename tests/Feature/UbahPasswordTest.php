<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UbahPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function kirim(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/ubah-password', $data);
    }

    public function test_ubah_password_berhasil_dan_bisa_login_dengan_password_baru(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $this->kirim([
            'password_lama'              => 'Password123!',
            'password_baru'              => 'PasswordBaru1',
            'password_baru_confirmation' => 'PasswordBaru1',
        ])->assertStatus(200);

        $pengguna->refresh();
        $this->assertTrue(Hash::check('PasswordBaru1', $pengguna->kata_sandi));
        $this->assertFalse(Hash::check('Password123!', $pengguna->kata_sandi));

        $this->postJson('/api/auth/login', ['username' => $pengguna->username, 'password' => 'PasswordBaru1'])->assertStatus(200);
    }

    public function test_password_lama_salah_ditolak(): void
    {
        $pengguna = $this->actingAsRole('ADMIN');

        $this->kirim([
            'password_lama'              => 'SalahSekali1',
            'password_baru'              => 'PasswordBaru1',
            'password_baru_confirmation' => 'PasswordBaru1',
        ])->assertStatus(422)->assertJsonPath('message', 'Password lama salah');

        $this->assertTrue(Hash::check('Password123!', $pengguna->refresh()->kata_sandi));
    }

    public function test_validasi_konfirmasi_panjang_dan_password_sama(): void
    {
        $this->actingAsRole('ADMIN');

        $this->kirim([
            'password_lama'              => 'Password123!',
            'password_baru'              => 'PasswordBaru1',
            'password_baru_confirmation' => 'BedaSendiri1',
        ])->assertStatus(422);

        $this->kirim([
            'password_lama'              => 'Password123!',
            'password_baru'              => 'pendek',
            'password_baru_confirmation' => 'pendek',
        ])->assertStatus(422);

        $this->kirim([
            'password_lama'              => 'Password123!',
            'password_baru'              => 'Password123!',
            'password_baru_confirmation' => 'Password123!',
        ])->assertStatus(422)->assertJsonPath('message', 'Password baru harus berbeda dari password lama');
    }

    public function test_ubah_password_mencabut_sesi_lain_tapi_sesi_sekarang_tetap(): void
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'ADMIN',
            'username'      => 'sesi_' . Str::random(6),
            'email'         => Str::random(6) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
        $tokenSekarang = $pengguna->createToken('mobile')->plainTextToken;
        $pengguna->createToken('web');

        $this->withHeader('Authorization', 'Bearer ' . $tokenSekarang)
            ->postJson('/api/auth/ubah-password', [
                'password_lama'              => 'Password123!',
                'password_baru'              => 'PasswordBaru1',
                'password_baru_confirmation' => 'PasswordBaru1',
            ])->assertStatus(200);

        $sisa = DB::table('personal_access_tokens')->where('tokenable_id', $pengguna->id_pengguna)->pluck('name')->all();
        $this->assertSame(['mobile'], $sisa);
    }
}
