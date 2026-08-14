<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanTitikDropTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Titik Drop Test',
        ]);
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'merk'          => 'Hino',
            'status'        => 'tersedia',
        ]);
    }

    private function makeSupir(): string
    {
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $idSupir,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Titik Drop Test',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return $idSupir;
    }

    public function test_store_penugasan_menyimpan_titik_drop_berurutan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'id_armada'  => $armada->id_armada,
            'id_supir'   => $idSupir,
            'titik_drop' => ['JLB', 'MRY', 'RDS'],
        ]);

        $res->assertStatus(201)->assertJsonPath('data.titik_drop', ['JLB', 'MRY', 'RDS']);

        $id = $res->json('data.id_penugasan');
        $rows = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
            ->whereNull('dihapus_pada')->orderBy('urutan')->get();
        $this->assertSame(['JLB', 'MRY', 'RDS'], $rows->pluck('lokasi')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('urutan')->map(fn ($u) => (int) $u)->all());
    }

    public function test_update_penugasan_replace_titik_drop(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada();
        $idSupir = $this->makeSupir();

        $create = $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'id_armada'  => $armada->id_armada,
            'id_supir'   => $idSupir,
            'titik_drop' => ['JLB', 'MRY'],
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/v1/penugasan/{$id}", [
            'titik_drop' => ['KPM'],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.titik_drop', ['KPM']);

        $aktif = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
            ->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $aktif);
        $this->assertSame('KPM', $aktif->first()->lokasi);

        $lama = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
            ->whereNotNull('dihapus_pada')->get();
        $this->assertCount(2, $lama);
    }

    public function test_update_penugasan_titik_drop_kosong_menghapus_semua(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $create = $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'titik_drop' => ['JLB', 'MRY'],
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/v1/penugasan/{$id}", [
            'titik_drop' => [],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.titik_drop', []);

        $aktif = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
            ->whereNull('dihapus_pada')->get();
        $this->assertCount(0, $aktif);

        $lama = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
            ->whereNotNull('dihapus_pada')->get();
        $this->assertCount(2, $lama);
    }

    public function test_titik_drop_lebih_dari_10_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'titik_drop' => array_map(fn ($i) => "LOKASI-{$i}", range(1, 11)),
        ]);

        $res->assertStatus(422);
    }

    public function test_update_tanpa_key_titik_drop_tidak_menghapus_yang_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $create = $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'titik_drop' => ['JLB'],
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id_penugasan');

        $res = $this->putJson("/api/v1/penugasan/{$id}", [
            'estimasi_biaya' => 150000,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.titik_drop', ['JLB']);

        $aktif = DB::table('titik_drop_penugasan')->where('id_penugasan', $id)
            ->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $aktif);
        $this->assertSame('JLB', $aktif->first()->lokasi);
    }

    public function test_show_penugasan_melampirkan_titik_drop(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $create = $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'titik_drop' => ['JLB', 'MRY'],
        ]);
        $id = $create->json('data.id_penugasan');

        $res = $this->getJson("/api/v1/penugasan/{$id}");

        $res->assertStatus(200)->assertJsonPath('data.titik_drop', ['JLB', 'MRY']);
    }

    public function test_index_penugasan_melampirkan_titik_drop(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();

        $this->postJson('/api/v1/penugasan', [
            'id_proyek'  => $proyek->id_proyek,
            'titik_drop' => ['JLB'],
        ])->assertStatus(201);

        $res = $this->getJson('/api/v1/penugasan?id_proyek=' . $proyek->id_proyek);

        $res->assertStatus(200);
        $this->assertSame(['JLB'], $res->json('data.0.titik_drop'));
    }
}
