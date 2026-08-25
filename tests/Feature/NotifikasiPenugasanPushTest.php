<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Notifikasi\NotifikasiModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifikasiPenugasanPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.firebase.credentials' => base_path('tests/fixtures/firebase-service-account.json')]);
        cache()->forget('fcm_access_token');
    }

    private function fakeFcmBerhasil(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'fcm.googleapis.com/*'    => Http::response(['name' => 'projects/tmn-test/messages/1']),
        ]);
    }

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Push Test',
        ]);
    }

    private function makeSupir(bool $denganAkun = true): array
    {
        $idPengguna = null;
        if ($denganAkun) {
            $pengguna = Pengguna::create([
                'id_pengguna'   => (string) Str::uuid(),
                'id_perusahaan' => self::PERUSAHAAN_ID,
                'kode_peran'    => 'SUPIR',
                'username'      => 'supir_' . Str::random(8),
                'email'         => Str::random(8) . '@test.id',
                'kata_sandi'    => bcrypt('Password123!'),
                'aktif'         => 1,
            ]);
            $idPengguna = $pengguna->id_pengguna;
        }

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupir,
            'id_pengguna'   => $idPengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Push Test',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

        return ['id_supir' => $idSupir, 'id_pengguna' => $idPengguna];
    }

    private function daftarkanToken(string $idPengguna, string $token = 'fcm-supir'): void
    {
        DB::table('token_perangkat')->insert([
            'id_token_perangkat' => (string) Str::uuid(),
            'id_pengguna'        => $idPengguna,
            'token'              => $token,
            'platform'           => 'android',
            'dibuat_pada'        => now(),
        ]);
    }

    public function test_create_penugasan_dengan_supir_membuat_notifikasi_dan_kirim_push(): void
    {
        $this->fakeFcmBerhasil();
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->daftarkanToken($supir['id_pengguna']);

        $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $supir['id_supir'],
        ])->assertStatus(201);

        $notif = NotifikasiModel::where('referensi_tipe', 'penugasan')->first();
        $this->assertNotNull($notif);
        $this->assertSame($supir['id_pengguna'], $notif->id_pengguna);
        $this->assertSame('penugasan', $notif->tipe);
        $this->assertStringContainsString('Proyek Push Test', $notif->judul);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), 'fcm.googleapis.com')
            && $req['message']['token'] === 'fcm-supir');
    }

    public function test_update_ganti_supir_mengirim_notif_ke_supir_baru_sekali(): void
    {
        $this->fakeFcmBerhasil();
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $lama = $this->makeSupir();
        $baru = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $lama['id_supir'],
        ])->assertStatus(201);
        $idPenugasan = $res->json('data.id_penugasan');

        $this->putJson("/api/penugasan/{$idPenugasan}", [
            'id_supir' => $baru['id_supir'],
        ])->assertStatus(200);

        $this->assertSame(1, NotifikasiModel::where('id_pengguna', $baru['id_pengguna'])
            ->where('referensi_tipe', 'penugasan')->count());
    }

    public function test_update_tanpa_sentuh_supir_tidak_membuat_notif_baru(): void
    {
        $this->fakeFcmBerhasil();
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $supir['id_supir'],
        ])->assertStatus(201);
        $idPenugasan = $res->json('data.id_penugasan');
        $jumlahAwal = NotifikasiModel::count();

        $this->putJson("/api/penugasan/{$idPenugasan}", [
            'tanggal_tugas' => now()->addDay()->toDateString(),
        ])->assertStatus(200);

        $this->assertSame($jumlahAwal, NotifikasiModel::count());
    }

    public function test_supir_tanpa_akun_tidak_membuat_record_dan_tetap_sukses(): void
    {
        $this->fakeFcmBerhasil();
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir(denganAkun: false);

        $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $supir['id_supir'],
        ])->assertStatus(201);

        $this->assertSame(0, NotifikasiModel::where('referensi_tipe', 'penugasan')->count());
    }

    public function test_tanpa_kredensial_penugasan_tetap_sukses_tanpa_http(): void
    {
        config(['services.firebase.credentials' => null]);
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->daftarkanToken($supir['id_pengguna']);

        $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $supir['id_supir'],
        ])->assertStatus(201);

        $this->assertSame(1, NotifikasiModel::where('referensi_tipe', 'penugasan')->count());
        Http::assertNothingSent();
    }

    public function test_respons_unregistered_menghapus_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]),
            'fcm.googleapis.com/*'    => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);

        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->daftarkanToken($supir['id_pengguna'], 'fcm-mati');

        $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $supir['id_supir'],
        ])->assertStatus(201);

        $this->assertNotNull(DB::table('token_perangkat')->where('token', 'fcm-mati')->value('dihapus_pada'));
    }
}
