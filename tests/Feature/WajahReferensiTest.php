<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WajahReferensiTest extends TestCase
{
    use RefreshDatabase;

    private function embeddingValid(): string
    {
        return json_encode(array_fill(0, 192, 0.05));
    }

    private function actingAsPengguna(string $kodePeran = 'SUPIR'): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => 'wajah_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function daftarWajah(): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/wajah/saya', [
            'foto'        => UploadedFile::fake()->create('wajah.jpg', 100, 'image/jpeg'),
            'embedding'   => $this->embeddingValid(),
            'model_versi' => 'mobilefacenet-v1',
        ], ['Accept' => 'application/json']);
    }

    public function test_saya_belum_terdaftar_mengembalikan_terdaftar_false(): void
    {
        $this->actingAsPengguna();

        $this->getJson('/api/wajah/saya')
            ->assertStatus(200)
            ->assertJsonPath('data.terdaftar', false)
            ->assertJsonPath('data.embedding', null)
            ->assertJsonPath('data.url_foto', null);
    }

    public function test_daftar_wajah_sukses_dan_bisa_diambil_kembali(): void
    {
        Storage::fake('public');
        $pengguna = $this->actingAsPengguna();

        $this->daftarWajah()
            ->assertStatus(201)
            ->assertJsonPath('data.terdaftar', true)
            ->assertJsonPath('data.model_versi', 'mobilefacenet-v1')
            ->assertJsonCount(192, 'data.embedding');

        $row = DB::table('wajah_referensi')->where('id_pengguna', $pengguna->id_pengguna)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::PERUSAHAAN_ID, $row->id_perusahaan);
        $this->assertStringStartsWith('wajah-referensi/', $row->path_foto);
        Storage::disk('public')->assertExists($row->path_foto);

        $this->getJson('/api/wajah/saya')
            ->assertStatus(200)
            ->assertJsonPath('data.terdaftar', true)
            ->assertJsonCount(192, 'data.embedding');
    }

    public function test_daftar_wajah_sebagai_karyawan_juga_bisa(): void
    {
        Storage::fake('public');
        $this->actingAsPengguna('KEUANGAN');

        $this->daftarWajah()->assertStatus(201)->assertJsonPath('data.terdaftar', true);
    }

    public function test_daftar_dua_kali_ditolak_409(): void
    {
        Storage::fake('public');
        $this->actingAsPengguna();

        $this->daftarWajah()->assertStatus(201);
        $this->daftarWajah()->assertStatus(409);
    }

    public function test_embedding_bukan_192_angka_ditolak_422(): void
    {
        Storage::fake('public');
        $this->actingAsPengguna();

        $this->post('/api/wajah/saya', [
            'foto'        => UploadedFile::fake()->create('wajah.jpg', 100, 'image/jpeg'),
            'embedding'   => json_encode([0.1, 0.2, 0.3]),
            'model_versi' => 'mobilefacenet-v1',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_embedding_bukan_json_ditolak_422(): void
    {
        Storage::fake('public');
        $this->actingAsPengguna();

        $this->post('/api/wajah/saya', [
            'foto'        => UploadedFile::fake()->create('wajah.jpg', 100, 'image/jpeg'),
            'embedding'   => 'bukan-json',
            'model_versi' => 'mobilefacenet-v1',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_tanpa_foto_ditolak_422(): void
    {
        $this->actingAsPengguna();

        $this->post('/api/wajah/saya', [
            'embedding'   => $this->embeddingValid(),
            'model_versi' => 'mobilefacenet-v1',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_tanpa_login_401(): void
    {
        $this->getJson('/api/wajah/saya')->assertStatus(401);
    }
}
