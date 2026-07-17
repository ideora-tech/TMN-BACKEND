<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Test',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeProyek(string $idKlien, string $kodeProyek): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => $kodeProyek,
            'nama_proyek'   => 'Proyek Existing',
        ]);
    }

    public function test_menolak_kode_proyek_duplikat_saat_membuat(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, '123');

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => '123',
            'nama_proyek' => 'Proyek Baru',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['kode_proyek']);
    }

    public function test_membuat_proyek_dengan_kode_unik_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-UNIK-1',
            'nama_proyek' => 'Proyek Baru',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.kode_proyek', 'PRJ-UNIK-1');
    }

    public function test_menolak_kode_proyek_duplikat_saat_update(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, 'KODE-A');
        $proyekB = $this->makeProyek($klien->id_klien, 'KODE-B');

        $res = $this->putJson("/api/v1/proyek/{$proyekB->id_proyek}", [
            'kode_proyek' => 'KODE-A',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['kode_proyek']);
    }

    public function test_update_proyek_dengan_kode_sendiri_tidak_ditolak(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $proyek = $this->makeProyek($klien->id_klien, 'KODE-SENDIRI');

        $res = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}", [
            'kode_proyek' => 'KODE-SENDIRI',
            'nama_proyek' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.nama_proyek', 'Nama Diperbarui');
    }
}
