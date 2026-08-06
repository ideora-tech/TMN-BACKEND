<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanListPerusahaanTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(string $idPerusahaan): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test',
        ]);
    }

    private function makeSupir(string $idPerusahaan, string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => $idPerusahaan, 'nama' => $nama,
            'no_sim' => 'SIM-' . Str::random(8), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenugasan(string $idProyek, string $idSupir, string $status = 'aktif'): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'status'        => $status,
            'tanggal_tugas' => now()->toDateString(),
        ]);
    }

    public function test_list_tanpa_filter_mengembalikan_penugasan_perusahaan_sendiri(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek(self::PERUSAHAAN_ID);
        $this->makePenugasan($proyek->id_proyek, $this->makeSupir(self::PERUSAHAAN_ID, 'Supir A'), 'aktif');
        $this->makePenugasan($proyek->id_proyek, $this->makeSupir(self::PERUSAHAAN_ID, 'Supir B'), 'selesai');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $proyekLain = $this->makeProyek($idLain);
        $this->makePenugasan($proyekLain->id_proyek, $this->makeSupir($idLain, 'Supir Lain'), 'aktif');

        $res = $this->getJson('/api/v1/penugasan');
        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));

        $resAktif = $this->getJson('/api/v1/penugasan?status=aktif');
        $resAktif->assertStatus(200);
        $this->assertCount(1, $resAktif->json('data'));
        $this->assertSame('aktif', $resAktif->json('data.0.status'));
    }
}
