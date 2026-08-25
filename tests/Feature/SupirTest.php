<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupir(string $idPerusahaan, string $nama = 'Supir Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_supir_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/supir', [
            'nama'   => 'Budi Santoso',
            'no_sim' => 'SIM-12345',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama', 'Budi Santoso')
            ->assertJsonPath('data.status', 'aktif');

        $this->assertDatabaseHas('supir', ['nama' => 'Budi Santoso', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    private function makeKaryawanTaut(string $nik = 'NIK-TAUT-01', string $nama = 'Karyawan Tautan'): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_membuat_supir_tertaut_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawanTaut();

        $res = $this->postJson('/api/supir', [
            'nama'        => 'Supir Tertaut',
            'no_sim'      => 'SIM-TAUT-1',
            'id_karyawan' => $idKaryawan,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.id_karyawan', $idKaryawan);
    }

    public function test_satu_karyawan_tidak_bisa_ditautkan_ke_dua_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawanTaut('NIK-TAUT-02');

        $this->postJson('/api/supir', [
            'nama' => 'Supir Pertama', 'no_sim' => 'SIM-TAUT-2', 'id_karyawan' => $idKaryawan,
        ])->assertStatus(201);

        $res = $this->postJson('/api/supir', [
            'nama' => 'Supir Kedua', 'no_sim' => 'SIM-TAUT-3', 'id_karyawan' => $idKaryawan,
        ]);

        $res->assertStatus(422);
    }

    public function test_karyawan_perusahaan_lain_tidak_bisa_ditautkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePerusahaanLain();
        $idKaryawanLain = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawanLain, 'id_perusahaan' => $idLain,
            'nik' => 'NIK-LAIN-TAUT', 'nama_karyawan' => 'Orang Lain', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->postJson('/api/supir', [
            'nama' => 'Supir Nakal', 'no_sim' => 'SIM-TAUT-4', 'id_karyawan' => $idKaryawanLain,
        ])->assertStatus(404);
    }

    private function makePenggunaSupir(?string $idPerusahaan = null, string $kodePeran = 'SUPIR', int $aktif = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_peran'    => $kodePeran,
            'username'      => '08' . random_int(1000000000, 9999999999),
            'email'         => Str::random(10) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => $aktif,
        ]);
        return $id;
    }

    public function test_menautkan_akun_pengguna_saat_create_dan_update(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePenggunaSupir();

        $res = $this->postJson('/api/supir', [
            'nama' => 'Supir Mobile', 'no_sim' => 'SIM-AKUN-1', 'id_pengguna' => $idPengguna,
        ]);
        $res->assertStatus(201)->assertJsonPath('data.id_pengguna', $idPengguna);

        $idSupir = $res->json('data.id_supir');
        $idPenggunaBaru = $this->makePenggunaSupir();

        $this->putJson("/api/supir/{$idSupir}", ['id_pengguna' => $idPenggunaBaru])
            ->assertStatus(200)->assertJsonPath('data.id_pengguna', $idPenggunaBaru);

        $this->putJson("/api/supir/{$idSupir}", ['id_pengguna' => $idPenggunaBaru])
            ->assertStatus(200)->assertJsonPath('data.id_pengguna', $idPenggunaBaru);

        $this->putJson("/api/supir/{$idSupir}", ['id_pengguna' => null])
            ->assertStatus(200)->assertJsonPath('data.id_pengguna', null);
    }

    public function test_satu_akun_tidak_bisa_ditautkan_ke_dua_supir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPengguna = $this->makePenggunaSupir();

        $this->postJson('/api/supir', [
            'nama' => 'Supir Pertama', 'no_sim' => 'SIM-AKUN-2', 'id_pengguna' => $idPengguna,
        ])->assertStatus(201);

        $res = $this->postJson('/api/supir', [
            'nama' => 'Supir Kedua', 'no_sim' => 'SIM-AKUN-3', 'id_pengguna' => $idPengguna,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('sudah ditautkan', (string) $res->json('message'));
    }

    public function test_akun_perusahaan_lain_tidak_bisa_ditautkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idLain = $this->makePerusahaanLain();
        $idPenggunaLain = $this->makePenggunaSupir($idLain);

        $this->postJson('/api/supir', [
            'nama' => 'Supir Nakal', 'no_sim' => 'SIM-AKUN-4', 'id_pengguna' => $idPenggunaLain,
        ])->assertStatus(404);
    }

    public function test_opsi_pengguna_hanya_peran_supir_aktif_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idBebas    = $this->makePenggunaSupir();
        $idTertaut  = $this->makePenggunaSupir();
        $this->makePenggunaSupir(null, 'MANAGER');
        $this->makePenggunaSupir(null, 'SUPIR', 0);
        $this->makePenggunaSupir($this->makePerusahaanLain());

        $this->postJson('/api/supir', [
            'nama' => 'Supir Tertaut Akun', 'no_sim' => 'SIM-AKUN-5', 'id_pengguna' => $idTertaut,
        ])->assertStatus(201);

        $res = $this->getJson('/api/supir/opsi-pengguna');

        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_pengguna');
        $this->assertCount(2, $data);
        $this->assertNull($data->get($idBebas)['id_supir_tertaut']);
        $this->assertSame('Supir Tertaut Akun', $data->get($idTertaut)['nama_supir_tertaut']);
    }

    public function test_list_supir_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeSupir(self::PERUSAHAAN_ID, 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeSupir($idLain, 'Milik Lain');

        $res = $this->getJson('/api/supir');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama']);
    }

    public function test_show_supir_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/supir/{$item->id_supir}");

        $res->assertStatus(200)->assertJsonPath('data.id_supir', $item->id_supir);
    }

    public function test_update_supir_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/supir/{$item->id_supir}", [
            'nama' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama', 'Nama Diperbarui');
    }

    public function test_hapus_supir_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/supir/{$item->id_supir}");
        $res->assertStatus(200);

        $row = DB::table('supir')->where('id_supir', $item->id_supir)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
