<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaporanProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(?string $idPerusahaan = null): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Laporan Proyek', 'dibuat_pada' => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-LP-' . Str::random(6),
            'nama_proyek'   => 'Proyek Laporan Uji',
        ]);
    }

    private function buatTripSelesai(string $idProyek, float $jarak, float $biayaBbm, float $biayaLain = 0): void
    {
        $penugasan = PenugasanModel::create(['id_proyek' => $idProyek]);
        $jadwal = JadwalKeberangkatanModel::create(['id_penugasan' => $penugasan->id_penugasan]);
        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'selesai']);

        $idLaporan = (string) Str::uuid();
        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => $idLaporan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => $jarak,
            'biaya_bbm' => $biayaBbm, 'uang_tol' => 50000, 'uang_jalan' => 100000,
            'dibuat_pada' => now(),
        ]);

        if ($biayaLain > 0) {
            DB::table('biaya_lain_trip')->insert([
                'id_biaya_lain' => (string) Str::uuid(), 'id_laporan' => $idLaporan,
                'nama_biaya' => 'Parkir', 'nominal' => $biayaLain, 'dibuat_pada' => now(),
            ]);
        }
    }

    public function test_list_memuat_nama_proyek_dan_total_trip_aktual(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $this->buatTripSelesai($proyek->id_proyek, 100, 200000);

        $this->postJson('/api/laporan', ['id_proyek' => $proyek->id_proyek, 'ringkasan' => 'Uji'])
            ->assertStatus(201)
            ->assertJsonPath('data.total_trip', 1);

        $this->buatTripSelesai($proyek->id_proyek, 50, 100000);

        $res = $this->getJson('/api/laporan');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_proyek', $proyek->id_proyek);
        $this->assertSame('Proyek Laporan Uji', $row['nama_proyek']);
        $this->assertSame('Klien Laporan Proyek', $row['nama_klien']);
        $this->assertSame(2, (int) $row['total_trip_aktual']);

        $cari = $this->getJson('/api/laporan?search=Laporan Uji');
        $this->assertCount(1, $cari->json('data'));
    }

    public function test_export_excel_dan_pdf_berfungsi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $this->buatTripSelesai($proyek->id_proyek, 100, 200000);
        $this->postJson('/api/laporan', ['id_proyek' => $proyek->id_proyek, 'ringkasan' => 'Uji export'])
            ->assertStatus(201);

        $this->get('/api/laporan/export/excel')->assertStatus(200);
        $this->get('/api/laporan/export/pdf')->assertStatus(200);
    }

    public function test_detail_memuat_statistik_dan_scoped_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $this->buatTripSelesai($proyek->id_proyek, 100, 200000, 25000);
        $this->buatTripSelesai($proyek->id_proyek, 50, 100000);

        $idLaporan = $this->postJson('/api/laporan', ['id_proyek' => $proyek->id_proyek])
            ->json('data.id_laporan');

        $res = $this->getJson("/api/laporan/{$idLaporan}");
        $res->assertStatus(200)
            ->assertJsonPath('data.kode_proyek', $proyek->kode_proyek)
            ->assertJsonPath('data.nama_klien', 'Klien Laporan Proyek')
            ->assertJsonPath('data.statistik.total_trip', 2)
            ->assertJsonPath('data.statistik.total_jarak_km', 150)
            ->assertJsonPath('data.statistik.total_biaya', 625000);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $proyekLain = $this->makeProyek($idPerusahaanLain);
        $idLaporanLain = (string) Str::uuid();
        DB::table('laporan_proyek')->insert([
            'id_laporan' => $idLaporanLain, 'id_proyek' => $proyekLain->id_proyek,
            'total_trip' => 0, 'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/laporan/{$idLaporanLain}")->assertStatus(404);
    }
}
