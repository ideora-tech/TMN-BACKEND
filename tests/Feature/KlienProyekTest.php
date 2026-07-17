<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KlienProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $nama = 'Klien Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => $nama,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeProyek(string $idKlien, string $nama): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => $nama,
        ]);
    }

    public function test_riwayat_proyek_hanya_menampilkan_proyek_klien_tersebut(): void
    {
        $this->actingAsRole('ADMIN');
        $klien1 = $this->makeKlien('Klien Satu');
        $klien2 = $this->makeKlien('Klien Dua');

        $this->makeProyek($klien1->id_klien, 'Proyek A');
        $this->makeProyek($klien1->id_klien, 'Proyek B');
        $this->makeProyek($klien2->id_klien, 'Proyek C');

        $res = $this->getJson("/api/v1/klien/{$klien1->id_klien}/proyek");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $row) {
            $this->assertSame($klien1->id_klien, $row['id_klien']);
        }
        $namaProyek = array_column($data, 'nama_proyek');
        $this->assertContains('Proyek A', $namaProyek);
        $this->assertContains('Proyek B', $namaProyek);
        $this->assertNotContains('Proyek C', $namaProyek);

        $this->assertArrayHasKey('meta', $res->json());
        $this->assertSame(2, $res->json('meta.total'));
    }

    public function test_riwayat_proyek_route_tidak_mengganggu_show_klien(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, 'Proyek A');

        $res = $this->getJson("/api/v1/klien/{$klien->id_klien}");
        $res->assertStatus(200)->assertJsonPath('data.id_klien', $klien->id_klien);
    }

    public function test_riwayat_proyek_klien_tanpa_proyek_mengembalikan_kosong(): void
    {
        $this->actingAsRole('ADMIN');
        $klien = $this->makeKlien('Klien Tanpa Proyek');

        $res = $this->getJson("/api/v1/klien/{$klien->id_klien}/proyek");

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
        $this->assertSame(0, $res->json('meta.total'));
    }

    public function test_menolak_riwayat_proyek_klien_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain,
            'nama'          => 'Perusahaan Lain Test',
            'dibuat_pada'   => now(),
        ]);

        $idKlienLain = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlienLain,
            'id_perusahaan' => $idPerusahaanLain,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Perusahaan Lain',
            'dibuat_pada'   => now(),
        ]);
        $klienLain = DB::table('klien')->where('id_klien', $idKlienLain)->first();

        $this->makeProyek($klienLain->id_klien, 'Proyek Perusahaan Lain');

        $res = $this->getJson("/api/v1/klien/{$klienLain->id_klien}/proyek");

        $res->assertStatus(404);
    }
}
