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

    private function makeJabatan(string $idPerusahaan, ?string $idDepartemen = null, string $nama = 'Staff'): object
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan'     => $id,
            'id_perusahaan'  => $idPerusahaan,
            'id_departemen'  => $idDepartemen,
            'kode_jabatan'   => 'JBT-' . Str::random(4),
            'nama_jabatan'   => $nama,
            'level'          => 1,
            'aktif'          => 1,
            'dibuat_pada'    => now(),
        ]);
        return DB::table('jabatan')->where('id_jabatan', $id)->first();
    }

    public function test_membuat_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jabatan', [
            'kode_jabatan' => 'JBT-01',
            'nama_jabatan' => 'Manager',
            'level'        => 3,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_jabatan', 'Manager')
            ->assertJsonPath('data.level', 3);

        $this->assertDatabaseHas('jabatan', [
            'kode_jabatan'  => 'JBT-01',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->getJson('/api/v1/jabatan');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
    }

    public function test_filter_jabatan_by_departemen(): void
    {
        $this->actingAsRole('ADMIN');
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
        $this->actingAsRole('ADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/jabatan/{$item->id_jabatan}");

        $res->assertStatus(200)->assertJsonPath('data.id_jabatan', $item->id_jabatan);
    }

    public function test_update_jabatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID, null, 'Lama');

        $res = $this->putJson("/api/v1/jabatan/{$item->id_jabatan}", [
            'nama_jabatan' => 'Baru',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_jabatan', 'Baru');
    }

    public function test_hapus_jabatan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeJabatan(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/jabatan/{$item->id_jabatan}");
        $res->assertStatus(200);

        $row = DB::table('jabatan')->where('id_jabatan', $item->id_jabatan)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
