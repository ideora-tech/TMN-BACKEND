<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JenisPerawatanTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenis(string $nama = 'Ganti Oli', ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id,
            'id_perusahaan'      => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'               => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('jenis_perawatan')->where('id_jenis_perawatan', $id)->first();
    }

    public function test_create_jenis_perawatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/jenis-perawatan', [
            'nama'       => 'Tune Up Mesin',
            'keterangan' => 'Servis rutin 10.000 km',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama', 'Tune Up Mesin')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('jenis_perawatan', [
            'nama'          => 'Tune Up Mesin',
            'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeJenis('Milik Sendiri');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $this->makeJenis('Milik Orang', $idLain);

        $res = $this->getJson('/api/v1/jenis-perawatan');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Milik Sendiri', $res->json('data.0.nama'));
    }

    public function test_update_dan_show_jenis_perawatan(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenis();

        $resUpdate = $this->putJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}", [
            'nama'  => 'Ganti Oli Mesin',
            'aktif' => false,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.nama', 'Ganti Oli Mesin')
            ->assertJsonPath('data.aktif', false);

        $resShow = $this->getJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}");
        $resShow->assertStatus(200)->assertJsonPath('data.nama', 'Ganti Oli Mesin');
    }

    public function test_delete_jenis_perawatan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $jenis = $this->makeJenis();

        $res = $this->deleteJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}");
        $res->assertStatus(200);

        $this->assertSoftDeleted('jenis_perawatan', ['id_jenis_perawatan' => $jenis->id_jenis_perawatan]);
        $this->getJson("/api/v1/jenis-perawatan/{$jenis->id_jenis_perawatan}")->assertStatus(404);
    }
}
