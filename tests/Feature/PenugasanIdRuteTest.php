<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanIdRuteTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Id Rute Test',
        ]);
    }

    private function makeRute(): string
    {
        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $idRute,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUTE-' . Str::random(8),
            'nama_rute'     => 'Rute Bekasi',
            'dibuat_pada'   => now(),
        ]);
        return $idRute;
    }

    public function test_store_penugasan_menyimpan_id_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idRute = $this->makeRute();

        $res = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $idRute,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.id_rute', $idRute);
        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $res->json('data.id_penugasan'),
            'id_rute'      => $idRute,
        ]);
    }

    public function test_show_dan_index_penugasan_melampirkan_id_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idRute = $this->makeRute();

        $create = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $idRute,
        ]);
        $id = $create->json('data.id_penugasan');

        $this->getJson("/api/penugasan/{$id}")
            ->assertStatus(200)->assertJsonPath('data.id_rute', $idRute);

        $this->getJson('/api/penugasan?id_proyek=' . $proyek->id_proyek)
            ->assertStatus(200)->assertJsonPath('data.0.id_rute', $idRute);
    }

    public function test_update_penugasan_bisa_mengganti_id_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idRuteAwal = $this->makeRute();
        $idRuteBaru = $this->makeRute();

        $create = $this->postJson('/api/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $idRuteAwal,
        ]);
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/penugasan/{$id}", ['id_rute' => $idRuteBaru]);

        $res->assertStatus(200)->assertJsonPath('data.id_rute', $idRuteBaru);
    }
}
