<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BiayaTagihanTripTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Biaya Tagihan',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $nama = 'Supir Biaya Tagihan'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeTrip(string $status = 'selesai'): TripModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Test Biaya Tagihan',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' BT',
            'merk'          => 'Hino',
        ])->id_armada;

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupir(),
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now()->subDay(),
            'rute'            => 'Jakarta - Bandung',
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    public function test_simpan_dan_replace_biaya_tagihan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $trip = $this->makeTrip('selesai');

        $createRes = $this->postJson("/api/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'      => 500000,
            'uang_jalan'     => 200000,
            'biaya_tagihan'  => [
                ['nama_biaya' => 'Multidrop', 'nominal' => 150000],
            ],
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.biaya_tagihan.0.nama_biaya', 'Multidrop');

        $idLaporan = $createRes->json('data.id_laporan');

        $this->assertSame(1, DB::table('biaya_tagihan_trip')
            ->where('id_laporan', $idLaporan)->whereNull('dihapus_pada')->count());
        $this->assertDatabaseHas('biaya_tagihan_trip', [
            'id_laporan'   => $idLaporan,
            'nama_biaya'   => 'Multidrop',
            'nominal'      => 150000,
            'dihapus_pada' => null,
        ]);

        $updateRes = $this->putJson("/api/laporan-perjalanan/{$idLaporan}", [
            'biaya_tagihan' => [
                ['nama_biaya' => 'TKBM', 'nominal' => 100000],
            ],
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.biaya_tagihan.0.nama_biaya', 'TKBM');

        $this->assertSame(1, DB::table('biaya_tagihan_trip')
            ->where('id_laporan', $idLaporan)->whereNull('dihapus_pada')->count());
        $this->assertDatabaseHas('biaya_tagihan_trip', [
            'id_laporan'   => $idLaporan,
            'nama_biaya'   => 'TKBM',
            'nominal'      => 100000,
            'dihapus_pada' => null,
        ]);
        $this->assertDatabaseMissing('biaya_tagihan_trip', [
            'id_laporan'   => $idLaporan,
            'nama_biaya'   => 'Multidrop',
            'dihapus_pada' => null,
        ]);
    }

    public function test_biaya_tagihan_tidak_mempengaruhi_total_realisasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $trip = $this->makeTrip('selesai');

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-perjalanan", [
            'uang_jalan'    => 100000,
            'biaya_tagihan' => [
                ['nama_biaya' => 'Multidrop', 'nominal' => 150000],
            ],
        ])->assertStatus(201);

        $res = $this->getJson("/api/trip/{$trip->id_trip}/rekap-biaya");

        $res->assertStatus(200);
        $this->assertEquals(100000, $res->json('data.total_keseluruhan'));
    }

    public function test_biaya_tagihan_ditolak_bila_sudah_difakturkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $trip = $this->makeTrip('selesai');

        $createRes = $this->postJson("/api/trip/{$trip->id_trip}/laporan-perjalanan", [
            'biaya_bbm'  => 500000,
            'uang_jalan' => 200000,
        ]);
        $createRes->assertStatus(201);
        $idLaporan = $createRes->json('data.id_laporan');

        $idFaktur = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur'      => $idFaktur,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'nomor_faktur'   => 'FK-TEST-' . Str::random(6),
            'total'          => 0,
            'status'         => 'draft',
            'tanggal_faktur' => now()->toDateString(),
            'dibuat_pada'    => now(),
        ]);
        DB::table('faktur_trip')->insert([
            'id_faktur_trip' => (string) Str::uuid(),
            'id_faktur'      => $idFaktur,
            'id_trip'        => $trip->id_trip,
            'dibuat_pada'    => now(),
        ]);

        $res = $this->putJson("/api/laporan-perjalanan/{$idLaporan}", [
            'biaya_bbm'     => 999999,
            'biaya_tagihan' => [
                ['nama_biaya' => 'Multidrop', 'nominal' => 150000],
            ],
        ]);

        $res->assertStatus(422);

        $this->assertSame(0, DB::table('biaya_tagihan_trip')
            ->where('id_laporan', $idLaporan)->whereNull('dihapus_pada')->count());
        $this->assertDatabaseHas('laporan_perjalanan', [
            'id_laporan' => $idLaporan,
            'biaya_bbm'  => 500000,
        ]);
    }
}
