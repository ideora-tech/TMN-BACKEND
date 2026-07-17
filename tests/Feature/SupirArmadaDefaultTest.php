<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirArmadaDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(?string $idPerusahaan = null): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'merk'          => 'Hino',
        ]);
    }

    private function makePerusahaanLain(): string
    {
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);
        return $idPerusahaanLain;
    }

    private function makeSupir(string $nama = 'Budi Santoso'): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }

    public function test_membuat_supir_dengan_id_armada_default_valid_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();

        $res = $this->postJson('/api/v1/supir', [
            'nama'              => 'Andi Wijaya',
            'no_sim'            => 'SIM-999',
            'id_armada_default' => $armada->id_armada,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_armada_default', $armada->id_armada);

        $this->assertDatabaseHas('supir', [
            'nama'              => 'Andi Wijaya',
            'id_armada_default' => $armada->id_armada,
        ]);
    }

    public function test_update_supir_set_id_armada_default_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $supir  = $this->makeSupir();
        $armada = $this->makeArmada();

        $res = $this->putJson("/api/v1/supir/{$supir->id_supir}", [
            'id_armada_default' => $armada->id_armada,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_armada_default', $armada->id_armada);

        $this->assertDatabaseHas('supir', [
            'id_supir'          => $supir->id_supir,
            'id_armada_default' => $armada->id_armada,
        ]);
    }

    public function test_membuat_supir_dengan_id_armada_default_milik_perusahaan_lain_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');
        $idPerusahaanLain = $this->makePerusahaanLain();
        $armadaLain       = $this->makeArmada($idPerusahaanLain);

        $res = $this->postJson('/api/v1/supir', [
            'nama'              => 'Coba Curang',
            'no_sim'            => 'SIM-000',
            'id_armada_default' => $armadaLain->id_armada,
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseMissing('supir', [
            'nama' => 'Coba Curang',
        ]);
    }

    public function test_membuat_supir_dengan_id_armada_default_tidak_ada_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/supir', [
            'nama'              => 'Coba Ghost',
            'no_sim'            => 'SIM-111',
            'id_armada_default' => (string) Str::uuid(),
        ]);

        $res->assertStatus(404);

        $this->assertDatabaseMissing('supir', [
            'nama' => 'Coba Ghost',
        ]);
    }

    public function test_membuat_supir_tanpa_id_armada_default_tetap_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/supir', [
            'nama'   => 'Tanpa Default',
            'no_sim' => 'SIM-222',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_armada_default', null);

        $this->assertDatabaseHas('supir', [
            'nama'              => 'Tanpa Default',
            'id_armada_default' => null,
        ]);
    }

    public function test_membuat_supir_dengan_armada_yang_sudah_dipegang_supir_lain_mengembalikan_422(): void
    {
        $this->actingAsRole('ADMIN');
        $armada    = $this->makeArmada();
        $pemegang  = $this->makeSupir('Pemegang Armada');

        DB::table('supir')
            ->where('id_supir', $pemegang->id_supir)
            ->update(['id_armada_default' => $armada->id_armada]);

        $res = $this->postJson('/api/v1/supir', [
            'nama'              => 'Coba Rebut',
            'no_sim'            => 'SIM-333',
            'id_armada_default' => $armada->id_armada,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Pemegang Armada', $res->json('message'));

        $this->assertDatabaseMissing('supir', [
            'nama' => 'Coba Rebut',
        ]);
    }

    public function test_update_supir_ke_armada_milik_supir_lain_mengembalikan_422(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $supirA = $this->makeSupir('Supir A');
        $supirB = $this->makeSupir('Supir B');

        DB::table('supir')
            ->where('id_supir', $supirB->id_supir)
            ->update(['id_armada_default' => $armada->id_armada]);

        $res = $this->putJson("/api/v1/supir/{$supirA->id_supir}", [
            'id_armada_default' => $armada->id_armada,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Supir B', $res->json('message'));

        $this->assertDatabaseHas('supir', [
            'id_supir'          => $supirA->id_supir,
            'id_armada_default' => null,
        ]);
    }

    public function test_update_supir_mempertahankan_armada_default_sendiri_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir('Supir Sendiri');

        DB::table('supir')
            ->where('id_supir', $supir->id_supir)
            ->update(['id_armada_default' => $armada->id_armada]);

        $res = $this->putJson("/api/v1/supir/{$supir->id_supir}", [
            'nama'              => 'Supir Sendiri Update',
            'id_armada_default' => $armada->id_armada,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id_armada_default', $armada->id_armada);

        $this->assertDatabaseHas('supir', [
            'id_supir'          => $supir->id_supir,
            'nama'              => 'Supir Sendiri Update',
            'id_armada_default' => $armada->id_armada,
        ]);
    }
}
