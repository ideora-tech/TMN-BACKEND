<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParameterBokTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisKendaraan(string $nama = 'CDD', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan,
            'kode_jenis'         => strtoupper($nama) . '-' . Str::random(4),
            'nama_jenis'         => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeJenisBbm(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_bbm')->insert([
            'id_jenis_bbm'  => $id,
            'id_perusahaan' => $idPerusahaan,
            'nama_bbm'      => 'Solar',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function payloadValid(array $override = []): array
    {
        return array_merge([
            'id_jenis_kendaraan'     => $this->makeJenisKendaraan(),
            'id_jenis_bbm'           => $this->makeJenisBbm(),
            'konsumsi_km_per_liter'  => 5,
            'biaya_ban_per_km'       => 500,
            'biaya_servis_per_km'    => 500,
            'biaya_tetap_bulanan'    => 10000000,
            'utilisasi_km_per_bulan' => 5000,
            'margin_persen'          => 10,
        ], $override);
    }

    public function test_membuat_parameter_bok_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/parameter-bok', $this->payloadValid());

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.konsumsi_km_per_liter', 5)
            ->assertJsonPath('data.nama_jenis', 'CDD')
            ->assertJsonPath('data.nama_bbm', 'Solar');
    }

    public function test_menolak_tanpa_field_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/parameter-bok', []);

        $res->assertStatus(422)->assertJsonValidationErrors([
            'id_jenis_kendaraan', 'id_jenis_bbm', 'konsumsi_km_per_liter', 'utilisasi_km_per_bulan',
        ]);
    }

    public function test_menolak_duplikat_jenis_kendaraan(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisKendaraan();
        $this->postJson('/api/v1/parameter-bok', $this->payloadValid(['id_jenis_kendaraan' => $idJenis]))
            ->assertStatus(201);

        $res = $this->postJson('/api/v1/parameter-bok', $this->payloadValid(['id_jenis_kendaraan' => $idJenis]));

        $res->assertStatus(422);
    }

    public function test_menolak_jenis_bbm_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);

        $res = $this->postJson('/api/v1/parameter-bok', $this->payloadValid([
            'id_jenis_bbm' => $this->makeJenisBbm($lain),
        ]));

        $res->assertStatus(404);
    }

    public function test_update_dan_hapus(): void
    {
        $this->actingAsRole('ADMIN');
        $created = $this->postJson('/api/v1/parameter-bok', $this->payloadValid())->json('data');
        $id = $created['id_parameter_bok'];

        $this->putJson("/api/v1/parameter-bok/{$id}", ['margin_persen' => 20])
            ->assertStatus(200)->assertJsonPath('data.margin_persen', 20);

        $this->deleteJson("/api/v1/parameter-bok/{$id}")->assertStatus(200);
        $row = DB::table('parameter_bok')->where('id_parameter_bok', $id)->first();
        $this->assertNotNull($row->dihapus_pada);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idParam = (string) Str::uuid();
        DB::table('parameter_bok')->insert([
            'id_parameter_bok'       => $idParam,
            'id_perusahaan'          => $lain,
            'id_jenis_kendaraan'     => $this->makeJenisKendaraan('CDE', $lain),
            'id_jenis_bbm'           => $this->makeJenisBbm($lain),
            'konsumsi_km_per_liter'  => 6,
            'utilisasi_km_per_bulan' => 4000,
            'aktif'                  => 1,
            'dibuat_pada'            => now(),
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/parameter-bok')->json('data'));
        $this->getJson("/api/v1/parameter-bok/{$idParam}")->assertStatus(404);
    }
}
