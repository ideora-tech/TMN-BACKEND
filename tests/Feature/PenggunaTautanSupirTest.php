<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenggunaTautanSupirTest extends TestCase
{
    use RefreshDatabase;

    private const PERUSAHAAN_LAIN = 'b8f3c1a2-0000-4000-8000-000000000099';

    private function makePerusahaanLain(): string
    {
        DB::table('perusahaan')->insertOrIgnore([
            'id_perusahaan' => self::PERUSAHAAN_LAIN, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now(),
        ]);
        return self::PERUSAHAAN_LAIN;
    }

    private function makePengguna(string $kodePeran = 'SUPIR', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => 'user_' . Str::random(8),
            'email'         => Str::random(10) . '@test.id',
            'kata_sandi'    => Hash::make('Password123!'),
            'aktif'         => 1,
        ]);
        return $id;
    }

    private function makeSupir(string $nama, ?string $idPengguna = null, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_pengguna' => $idPengguna,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8), 'jenis_sim' => 'B2',
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeVendor(?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_vendor' => 'VND-' . Str::random(6), 'nama_vendor' => 'PT Mitra Angkut', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSupirVendor(string $nama, string $idVendor, ?string $idPengguna = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $id, 'id_vendor' => $idVendor, 'id_pengguna' => $idPengguna,
            'nama' => $nama, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadAkun(array $override = []): array
    {
        return array_merge([
            'username' => 'akun_' . Str::random(8), 'email' => Str::random(10) . '@test.id',
            'password' => 'Password123!', 'kode_peran' => 'SUPIR', 'aktif' => true,
        ], $override);
    }

    private function akunSupir(string $idSupir): ?string
    {
        return DB::table('supir')->where('id_supir', $idSupir)->value('id_pengguna');
    }

    private function akunSupirVendor(string $idSupirVendor): ?string
    {
        return DB::table('supir_vendor')->where('id_supir_vendor', $idSupirVendor)->value('id_pengguna');
    }

    public function test_buat_akun_supir_langsung_tertaut_ke_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->makeSupir('Budi');

        $idPengguna = $this->postJson('/api/pengguna', $this->payloadAkun(['id_supir' => $idSupir]))
            ->assertStatus(201)->json('data.id_pengguna');

        $this->assertSame($idPengguna, $this->akunSupir($idSupir));
    }

    public function test_buat_akun_supir_tanpa_memilih_supir_tetap_bisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->makeSupir('Budi');

        $this->postJson('/api/pengguna', $this->payloadAkun())->assertStatus(201);

        $this->assertNull($this->akunSupir($idSupir));
    }

    public function test_supir_yang_sudah_punya_akun_ditolak_dan_akun_batal_dibuat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAkunLama = $this->makePengguna();
        $idSupir = $this->makeSupir('Budi', $idAkunLama);
        $payload = $this->payloadAkun(['id_supir' => $idSupir]);

        $res = $this->postJson('/api/pengguna', $payload)->assertStatus(422);

        $this->assertStringContainsString('sudah memakai akun', (string) $res->json('message'));
        $this->assertSame($idAkunLama, $this->akunSupir($idSupir));
        $this->assertFalse(DB::table('pengguna')->where('username', $payload['username'])->exists());
    }

    public function test_supir_dengan_akun_terhapus_boleh_ditautkan_ke_akun_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAkunLama = $this->makePengguna();
        DB::table('pengguna')->where('id_pengguna', $idAkunLama)->update(['dihapus_pada' => now()]);
        $idSupir = $this->makeSupir('Budi', $idAkunLama);

        $idPengguna = $this->postJson('/api/pengguna', $this->payloadAkun(['id_supir' => $idSupir]))
            ->assertStatus(201)->json('data.id_pengguna');

        $this->assertSame($idPengguna, $this->akunSupir($idSupir));
    }

    public function test_ubah_akun_memindahkan_tautan_ke_supir_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $idSupirLama = $this->makeSupir('Budi', $idPengguna);
        $idSupirBaru = $this->makeSupir('Agus');

        $this->putJson("/api/pengguna/{$idPengguna}", ['id_supir' => $idSupirBaru])->assertStatus(200);

        $this->assertNull($this->akunSupir($idSupirLama));
        $this->assertSame($idPengguna, $this->akunSupir($idSupirBaru));
    }

    public function test_ubah_akun_dengan_supir_kosong_melepas_tautan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $idSupir = $this->makeSupir('Budi', $idPengguna);

        $this->putJson("/api/pengguna/{$idPengguna}", ['id_supir' => null])->assertStatus(200);

        $this->assertNull($this->akunSupir($idSupir));
    }

    public function test_ubah_akun_tanpa_mengirim_supir_tidak_mengubah_tautan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $idSupir = $this->makeSupir('Budi', $idPengguna);

        $this->putJson("/api/pengguna/{$idPengguna}", ['email' => 'baru_' . Str::random(6) . '@test.id'])->assertStatus(200);

        $this->assertSame($idPengguna, $this->akunSupir($idSupir));
    }

    public function test_ubah_akun_ke_supir_milik_akun_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $idAkunLain = $this->makePengguna();
        $idSupirSendiri = $this->makeSupir('Budi', $idPengguna);
        $idSupirOrang = $this->makeSupir('Agus', $idAkunLain);

        $this->putJson("/api/pengguna/{$idPengguna}", ['id_supir' => $idSupirOrang])->assertStatus(422);

        $this->assertSame($idPengguna, $this->akunSupir($idSupirSendiri));
        $this->assertSame($idAkunLain, $this->akunSupir($idSupirOrang));
    }

    public function test_peran_diganti_dari_supir_melepas_tautan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $idSupir = $this->makeSupir('Budi', $idPengguna);

        $this->putJson("/api/pengguna/{$idPengguna}", ['kode_peran' => 'MANAGER'])->assertStatus(200);

        $this->assertNull($this->akunSupir($idSupir));
    }

    public function test_supir_tidak_bisa_ditautkan_ke_akun_bukan_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupir = $this->makeSupir('Budi');

        $this->postJson('/api/pengguna', $this->payloadAkun(['kode_peran' => 'MANAGER', 'id_supir' => $idSupir]))
            ->assertStatus(422);

        $this->assertNull($this->akunSupir($idSupir));
    }

    public function test_supir_perusahaan_lain_tidak_bisa_ditautkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupirLain = $this->makeSupir('Supir Lain', null, $this->makePerusahaanLain());

        $this->postJson('/api/pengguna', $this->payloadAkun(['id_supir' => $idSupirLain]))->assertStatus(404);

        $this->assertNull($this->akunSupir($idSupirLain));
    }

    public function test_buat_akun_supir_vendor_langsung_tertaut(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupirVendor = $this->makeSupirVendor('Driver Mitra', $this->makeVendor());

        $idPengguna = $this->postJson('/api/pengguna', $this->payloadAkun(['kode_peran' => 'SUPIR_VENDOR', 'id_supir_vendor' => $idSupirVendor]))
            ->assertStatus(201)->json('data.id_pengguna');

        $this->assertSame($idPengguna, $this->akunSupirVendor($idSupirVendor));
    }

    public function test_supir_vendor_yang_sudah_punya_akun_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAkunLama = $this->makePengguna('SUPIR_VENDOR');
        $idSupirVendor = $this->makeSupirVendor('Driver Mitra', $this->makeVendor(), $idAkunLama);

        $this->postJson('/api/pengguna', $this->payloadAkun(['kode_peran' => 'SUPIR_VENDOR', 'id_supir_vendor' => $idSupirVendor]))
            ->assertStatus(422);

        $this->assertSame($idAkunLama, $this->akunSupirVendor($idSupirVendor));
    }

    public function test_supir_vendor_perusahaan_lain_tidak_bisa_ditautkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idSupirVendor = $this->makeSupirVendor('Driver Lain', $this->makeVendor($this->makePerusahaanLain()));

        $this->postJson('/api/pengguna', $this->payloadAkun(['kode_peran' => 'SUPIR_VENDOR', 'id_supir_vendor' => $idSupirVendor]))
            ->assertStatus(404);
    }

    public function test_peran_diganti_dari_supir_ke_supir_vendor_memindahkan_tautan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $idSupir = $this->makeSupir('Budi', $idPengguna);
        $idSupirVendor = $this->makeSupirVendor('Driver Mitra', $this->makeVendor());

        $this->putJson("/api/pengguna/{$idPengguna}", ['kode_peran' => 'SUPIR_VENDOR', 'id_supir_vendor' => $idSupirVendor])
            ->assertStatus(200);

        $this->assertNull($this->akunSupir($idSupir));
        $this->assertSame($idPengguna, $this->akunSupirVendor($idSupirVendor));
    }

    public function test_tautan_basi_ke_akun_bukan_supir_bisa_diambil_alih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAkunBasi = $this->makePengguna('MANAGER');
        $idSupir = $this->makeSupir('Budi', $idAkunBasi);

        $idPengguna = $this->postJson('/api/pengguna', $this->payloadAkun(['id_supir' => $idSupir]))
            ->assertStatus(201)->json('data.id_pengguna');

        $this->assertSame($idPengguna, $this->akunSupir($idSupir));
    }

    public function test_menyimpan_akun_bukan_supir_melepas_tautan_basi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idAkun = $this->makePengguna('MANAGER');
        $idSupir = $this->makeSupir('Budi', $idAkun);
        $idSupirVendor = $this->makeSupirVendor('Driver Mitra', $this->makeVendor(), $idAkun);

        $this->putJson("/api/pengguna/{$idAkun}", ['aktif' => true])->assertStatus(200);

        $this->assertNull($this->akunSupir($idSupir));
        $this->assertNull($this->akunSupirVendor($idSupirVendor));
    }

    public function test_opsi_supir_memuat_akun_tertaut_dan_hanya_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePengguna();
        $username = DB::table('pengguna')->where('id_pengguna', $idPengguna)->value('username');
        $idTertaut = $this->makeSupir('Agus', $idPengguna);
        $idBebas = $this->makeSupir('Budi');
        $idLain = $this->makeSupir('Supir Lain', null, $this->makePerusahaanLain());
        $idVendor = $this->makeVendor();
        $idSupirVendor = $this->makeSupirVendor('Driver Mitra', $idVendor);
        $idSupirVendorLain = $this->makeSupirVendor('Driver Lain', $this->makeVendor(self::PERUSAHAAN_LAIN));

        $res = $this->getJson('/api/pengguna/opsi-supir')->assertStatus(200);
        $supir = collect($res->json('data.supir'))->keyBy('id_supir');
        $supirVendor = collect($res->json('data.supir_vendor'))->keyBy('id_supir_vendor');

        $this->assertSame($idPengguna, $supir[$idTertaut]['id_pengguna']);
        $this->assertSame($username, $supir[$idTertaut]['username_pengguna']);
        $this->assertNull($supir[$idBebas]['id_pengguna']);
        $this->assertFalse($supir->has($idLain));
        $this->assertSame('PT Mitra Angkut', $supirVendor[$idSupirVendor]['nama_vendor']);
        $this->assertFalse($supirVendor->has($idSupirVendorLain));
    }
}
