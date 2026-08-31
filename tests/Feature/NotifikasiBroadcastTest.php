<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Notifikasi\NotifikasiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotifikasiBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPeran(string $kodePeran): Pengguna
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => strtolower($kodePeran) . '_' . Str::random(6),
            'email'         => Str::random(8) . '@notif.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function buatNotifikasi(?string $idPengguna, string $judul): void
    {
        NotifikasiModel::create([
            'id_notifikasi'  => (string) Str::uuid(),
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_pengguna'    => $idPengguna,
            'judul'          => $judul,
            'isi'            => 'Isi ' . $judul,
            'tipe'           => 'penugasan',
            'referensi_id'   => (string) Str::uuid(),
            'referensi_tipe' => 'penugasan',
            'dibaca'         => 0,
        ]);
    }

    public function test_akun_supir_tidak_melihat_notifikasi_broadcast(): void
    {
        $supir = $this->actingAsPeran('SUPIR');
        $this->buatNotifikasi(null, 'Broadcast Perusahaan');
        $this->buatNotifikasi($supir->id_pengguna, 'Milik Supir');

        $res = $this->getJson('/api/notifikasi')->assertStatus(200);
        $judul = collect($res->json('data'))->pluck('judul');

        $this->assertTrue($judul->contains('Milik Supir'));
        $this->assertFalse($judul->contains('Broadcast Perusahaan'));

        $this->getJson('/api/notifikasi/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_akun_supir_vendor_tidak_melihat_broadcast(): void
    {
        $sv = $this->actingAsPeran('SUPIR_VENDOR');
        $this->buatNotifikasi(null, 'Broadcast Perusahaan');

        $res = $this->getJson('/api/notifikasi')->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_akun_kantor_tetap_melihat_broadcast(): void
    {
        $admin = $this->actingAsPeran('SUPERADMIN');
        $this->buatNotifikasi(null, 'Broadcast Perusahaan');
        $this->buatNotifikasi($admin->id_pengguna, 'Milik Admin');

        $res = $this->getJson('/api/notifikasi')->assertStatus(200);
        $judul = collect($res->json('data'))->pluck('judul');

        $this->assertTrue($judul->contains('Broadcast Perusahaan'));
        $this->assertTrue($judul->contains('Milik Admin'));
    }
}
