<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JabatanTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartemen(string $idPerusahaan, string $nama = 'Operasional'): string
    {
        $id = (string) Str::uuid();
        DB::table('departemen')->insert([
            'id_departemen'   => $id,
            'id_perusahaan'   => $idPerusahaan,
            'kode_departemen' => 'DEP-' . Str::random(4),
            'nama_departemen' => $nama,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function makeJabatan(string $idPerusahaan, ?string $idDepartemen = null, string $nama = 'Staff', ?string $idJabatanInduk = null): object
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'       => $id,
            'id_perusahaan'    => $idPerusahaan,
            'id_departemen'    => $idDepartemen,
            'id_jabatan_induk' => $idJabatanInduk,
            'kode_jabatan'     => 'JBT-' . Str::random(4),
            'nama_jabatan'     => $nama,
            'level'            => 1,
            'aktif'            => 1,
            'dibuat_pada'      => now(),
        ]);
        return DB::table('jabatan')->where('id_jabatan', $id)->first();
    }

    private function makeKaryawan(string $idPerusahaan, string $idJabatan, string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'   => $id,
            'id_perusahaan' => $idPerusahaan,
            'id_jabatan'    => $idJabatan,
            'nik'           => 'NIK-' . Str::random(8),
            'nama_karyawan' => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_membuat_jabatan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan' => 'JBT-01',
            'nama_jabatan' => 'Manager',
            'level'        => 3,
            'tunjangan_jabatan' => 1500000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_jabatan', 'Manager')
            ->assertJsonPath('data.level', 3);
        $this->assertEquals(1500000, $res->json('data.tunjangan_jabatan'));

        $this->assertDatabaseHas('jabatan', [
            'kode_jabatan'  => 'JBT-01',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_jabatan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->getJson('/api/v1/jabatan');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_filter_jabatan_by_departemen(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idDepA = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Operasional');
        $idDepB = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Keuangan');
        $this->makeJabatan(self::PERUSAHAAN_ID, $idDepA, 'Supir');
        $this->makeJabatan(self::PERUSAHAAN_ID, $idDepB, 'Akuntan');

        $res = $this->getJson("/api/v1/jabatan?id_departemen={$idDepA}");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Supir', $data[0]['nama_jabatan']);
    }

    public function test_show_jabatan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/jabatan/{$item->id_jabatan}");

        $res->assertStatus(200)->assertJsonPath('data.id_jabatan', $item->id_jabatan);
    }

    public function test_update_jabatan_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Lama');

        $res = $this->putJson("/api/v1/jabatan/{$item->id_jabatan}", [
            'nama_jabatan' => 'Baru',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_jabatan', 'Baru');
    }

    public function test_hapus_jabatan_soft_delete(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/jabatan/{$item->id_jabatan}");
        $res->assertStatus(200);

        $row = DB::table('jabatan')->where('id_jabatan', $item->id_jabatan)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_membuat_jabatan_dengan_atasan_valid_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $atasan = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Manager');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan'     => 'JBT-BAWAHAN',
            'nama_jabatan'     => 'Staff',
            'id_jabatan_induk' => $atasan->id_jabatan,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_jabatan_induk', $atasan->id_jabatan);
        $this->assertDatabaseHas('jabatan', [
            'kode_jabatan'     => 'JBT-BAWAHAN',
            'id_jabatan_induk' => $atasan->id_jabatan,
        ]);
    }

    public function test_jabatan_atasan_diri_sendiri_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $jabatan = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Manager');

        $res = $this->putJson("/api/v1/jabatan/{$jabatan->id_jabatan}", [
            'id_jabatan_induk' => $jabatan->id_jabatan,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('jabatan', [
            'id_jabatan'       => $jabatan->id_jabatan,
            'id_jabatan_induk' => null,
        ]);
    }

    public function test_jabatan_atasan_membentuk_siklus_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $a = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'A');
        $b = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'B');

        $this->putJson("/api/v1/jabatan/{$b->id_jabatan}", ['id_jabatan_induk' => $a->id_jabatan])
            ->assertStatus(200);

        $res = $this->putJson("/api/v1/jabatan/{$a->id_jabatan}", ['id_jabatan_induk' => $b->id_jabatan]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('jabatan', [
            'id_jabatan'       => $a->id_jabatan,
            'id_jabatan_induk' => null,
        ]);
    }

    public function test_jabatan_atasan_dari_perusahaan_lain_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain',
            'dibuat_pada'   => now(),
        ]);
        $jabatanLain = $this->makeJabatan($idPerusahaanLain, null, 'Manager Lain');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan'     => 'JBT-LINTAS',
            'nama_jabatan'     => 'Staff Lintas',
            'id_jabatan_induk' => $jabatanLain->id_jabatan,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('jabatan', [
            'kode_jabatan' => 'JBT-LINTAS',
        ]);
    }

    public function test_endpoint_struktur_organisasi_menyusun_pohon_dengan_jumlah_karyawan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idDepartemen = $this->makeDepartemen(self::PERUSAHAAN_ID, 'Operasional');

        $direktur = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Direktur Utama');
        $this->makeKaryawan(self::PERUSAHAAN_ID, $direktur->id_jabatan, 'Budi Direktur');

        $manager = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Manager Operasional', $direktur->id_jabatan);

        $staff = $this->makeJabatan(self::PERUSAHAAN_ID, $idDepartemen, 'Supir', $manager->id_jabatan);
        $this->makeKaryawan(self::PERUSAHAAN_ID, $staff->id_jabatan, 'Wawan Supir');
        $this->makeKaryawan(self::PERUSAHAAN_ID, $staff->id_jabatan, 'Slamet Supir');
        $this->makeKaryawan(self::PERUSAHAAN_ID, $staff->id_jabatan, 'Dedi Supir');

        $res = $this->getJson('/api/v1/jabatan/struktur-organisasi');

        $res->assertStatus(200);
        $pohon = $res->json('data');

        $this->assertCount(1, $pohon);
        $root = $pohon[0];
        $this->assertSame($direktur->id_jabatan, $root['id_jabatan']);
        $this->assertSame('Direktur Utama', $root['nama_jabatan']);
        $this->assertArrayNotHasKey('id_jabatan_induk', $root);
        $this->assertSame(1, $root['jumlah_karyawan']);
        $this->assertSame('Budi Direktur', $root['karyawan'][0]['nama_karyawan']);

        $this->assertCount(1, $root['children']);
        $anakManager = $root['children'][0];
        $this->assertSame($manager->id_jabatan, $anakManager['id_jabatan']);
        $this->assertSame(0, $anakManager['jumlah_karyawan']);
        $this->assertSame([], $anakManager['karyawan']);

        $this->assertCount(1, $anakManager['children']);
        $anakStaff = $anakManager['children'][0];
        $this->assertSame($staff->id_jabatan, $anakStaff['id_jabatan']);
        $this->assertSame('Supir', $anakStaff['nama_jabatan']);
        $this->assertSame($idDepartemen, $anakStaff['id_departemen']);
        $this->assertSame('Operasional', $anakStaff['nama_departemen']);
        $this->assertSame(3, $anakStaff['jumlah_karyawan']);
        $this->assertCount(3, $anakStaff['karyawan']);
    }

    public function test_jabatan_dengan_induk_nonaktif_tampil_sebagai_root(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $induk = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Manager Lama');
        $anak = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Staff', $induk->id_jabatan);

        DB::table('jabatan')->where('id_jabatan', $induk->id_jabatan)->update(['aktif' => 0]);

        $res = $this->getJson('/api/v1/jabatan/struktur-organisasi');

        $res->assertStatus(200);
        $pohon = $res->json('data');

        $ids = array_column($pohon, 'id_jabatan');
        $this->assertContains($anak->id_jabatan, $ids);
        $this->assertNotContains($induk->id_jabatan, $ids);
    }
}
