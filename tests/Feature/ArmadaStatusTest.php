<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penyeragaman kosakata status armada: tersedia | digunakan | perawatan | tidak_aktif.
 * Nilai lama dari form lama (aktif, servis, nonaktif) tidak boleh lolos validasi lagi.
 */
class ArmadaStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $status = 'tersedia'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . Str::random(3),
            'merk'          => 'Hino',
            'status'        => $status,
        ]);
    }

    public function test_create_armada_status_tersedia_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'  => 'B ' . random_int(1000, 9999) . ' TST',
            'status' => 'tersedia',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'tersedia');
    }

    public function test_create_armada_status_perawatan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'  => 'B ' . random_int(1000, 9999) . ' TST',
            'status' => 'perawatan',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'perawatan');
    }

    public function test_create_armada_status_nilai_lama_aktif_ditolak(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/armada', [
            'nopol'  => 'B ' . random_int(1000, 9999) . ' TST',
            'status' => 'aktif',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_update_armada_status_digunakan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');

        $res = $this->putJson("/api/v1/armada/{$armada->id_armada}", [
            'status' => 'digunakan',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.status', 'digunakan');
    }

    public function test_update_armada_status_nilai_lama_servis_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');

        $res = $this->putJson("/api/v1/armada/{$armada->id_armada}", [
            'status' => 'servis',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_penugasan_internal_dengan_armada_tersedia_berhasil_dan_status_jadi_digunakan(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('tersedia');
        $proyek = $this->makeProyekPenugasan();

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);

        $res->assertStatus(201);
        $this->assertSame('digunakan', $armada->fresh()->status);
    }

    public function test_penugasan_internal_dengan_armada_digunakan_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $armada = $this->makeArmada('digunakan');
        $proyek = $this->makeProyekPenugasan();

        $res = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $armada->id_armada,
        ]);

        $res->assertStatus(422);
    }

    private function makeProyekPenugasan(): \App\Modules\Proyek\ProyekModel
    {
        $idKlien = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Status Test',
            'dibuat_pada'   => now(),
        ]);

        return \App\Modules\Proyek\ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Status Test',
        ]);
    }
}
