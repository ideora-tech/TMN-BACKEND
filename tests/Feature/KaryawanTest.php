<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $idPerusahaan, string $nik, string $nama = 'Karyawan Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => $idPerusahaan,
            'nik'                => $nik,
            'nama_karyawan'      => $nama,
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('karyawan')->where('id_karyawan', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_karyawan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/karyawan', [
            'nik'                => 'NIK-001',
            'nama_karyawan'      => 'Andi Wijaya',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 5000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_karyawan', 'Andi Wijaya')
            ->assertJsonPath('data.gaji_pokok', 5000000)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('karyawan', ['nik' => 'NIK-001', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_menolak_nik_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-DUP');

        $res = $this->postJson('/api/v1/karyawan', [
            'nik'                => 'NIK-DUP',
            'nama_karyawan'      => 'Duplikat',
            'status_kepegawaian' => 'tetap',
        ]);

        $res->assertStatus(422);
    }

    public function test_list_karyawan_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-A', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeKaryawan($idLain, 'NIK-B', 'Milik Lain');

        $res = $this->getJson('/api/v1/karyawan');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_karyawan']);
    }

    public function test_show_karyawan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-SHOW');

        $res = $this->getJson("/api/v1/karyawan/{$item->id_karyawan}");

        $res->assertStatus(200)->assertJsonPath('data.id_karyawan', $item->id_karyawan);
    }

    public function test_show_karyawan_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/karyawan/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_karyawan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-UPD');

        $res = $this->putJson("/api/v1/karyawan/{$item->id_karyawan}", [
            'nama_karyawan' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_karyawan', 'Nama Diperbarui');
        $this->assertDatabaseHas('karyawan', ['id_karyawan' => $item->id_karyawan, 'nama_karyawan' => 'Nama Diperbarui']);
    }

    public function test_hapus_karyawan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-DEL');

        $res = $this->deleteJson("/api/v1/karyawan/{$item->id_karyawan}");
        $res->assertStatus(200);

        $row = DB::table('karyawan')->where('id_karyawan', $item->id_karyawan)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
