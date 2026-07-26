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

class TripSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Settlement',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(string $nama = 'Supir Settle'): string
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

    private function makeTripSelesai(?float $alokasi = null, string $namaSupir = 'Supir Settle'): TripModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Settlement',
        ]);

        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' ST',
            'merk'          => 'Hino',
        ])->id_armada;

        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $this->makeSupir($namaSupir),
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now()->subDay(),
            'rute'            => 'Jakarta - Semarang',
        ]);

        return TripModel::create([
            'id_jadwal'          => $jadwal->id_jadwal,
            'status'             => 'selesai',
            'uang_jalan_alokasi' => $alokasi,
        ]);
    }

    private function makeLaporan(string $idTrip, float $bbm = 100000, float $tol = 50000): string
    {
        $id = (string) Str::uuid();
        DB::table('laporan_perjalanan')->insert([
            'id_laporan'    => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip'       => $idTrip,
            'biaya_bbm'     => $bbm,
            'uang_jalan'    => 0,
            'uang_tol'      => $tol,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    public function test_mulai_trip_menyimpan_alokasi_uang_jalan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Mulai Alokasi',
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $this->makeSupir('Supir Alokasi'),
        ]);

        $res = $this->postJson('/api/v1/trip/mulai', [
            'id_penugasan'       => $penugasan->id_penugasan,
            'uang_jalan_alokasi' => 750000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.uang_jalan_alokasi', 750000)
            ->assertJsonPath('data.status_settlement', 'belum');
    }

    public function test_settlement_list_menghitung_realisasi_dan_selisih(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $trip = $this->makeTripSelesai(500000);
        $idLaporan = $this->makeLaporan($trip->id_trip, 200000, 100000);
        DB::table('biaya_lain_trip')->insert([
            'id_biaya_lain' => (string) Str::uuid(),
            'id_laporan'    => $idLaporan,
            'nama_biaya'    => 'Parkir',
            'nominal'       => 50000,
            'dibuat_pada'   => now(),
        ]);

        $res = $this->getJson('/api/v1/trip/settlement');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(500000, $data[0]['uang_jalan_alokasi']);
        $this->assertEquals(350000, $data[0]['total_realisasi']);
        $this->assertEquals(150000, $data[0]['selisih']);
        $this->assertSame('belum', $data[0]['status_settlement']);
        $this->assertTrue($data[0]['ada_laporan']);
    }

    public function test_settlement_list_hanya_trip_selesai_dan_bisa_filter_status(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $tripSelesai = $this->makeTripSelesai(300000, 'Supir Selesai');
        $this->makeLaporan($tripSelesai->id_trip);

        // trip berjalan tidak boleh muncul
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Berjalan',
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $this->makeSupir('Supir Jalan'),
        ]);
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
            'rute'            => 'Jakarta - Bogor',
        ]);
        TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'berjalan']);

        $res = $this->getJson('/api/v1/trip/settlement');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));

        $resLunas = $this->getJson('/api/v1/trip/settlement?status_settlement=lunas');
        $resLunas->assertStatus(200);
        $this->assertCount(0, $resLunas->json('data'));
    }

    public function test_tandai_lunas_dan_batalkan_lunas(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $trip = $this->makeTripSelesai(400000);

        $resLunas = $this->postJson("/api/v1/trip/{$trip->id_trip}/settlement/lunas", [
            'catatan' => 'Sisa dikembalikan tunai',
        ]);
        $resLunas->assertStatus(200)
            ->assertJsonPath('data.status_settlement', 'lunas')
            ->assertJsonPath('data.catatan_settlement', 'Sisa dikembalikan tunai');

        $resDobel = $this->postJson("/api/v1/trip/{$trip->id_trip}/settlement/lunas");
        $resDobel->assertStatus(422);

        $resBatal = $this->postJson("/api/v1/trip/{$trip->id_trip}/settlement/batal");
        $resBatal->assertStatus(200)->assertJsonPath('data.status_settlement', 'belum');
    }

    public function test_trip_belum_selesai_tidak_bisa_dilunas(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Belum Selesai',
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_supir'  => $this->makeSupir('Supir Belum'),
        ]);
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
            'rute'            => 'Jakarta - Cikampek',
        ]);
        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'berjalan']);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/settlement/lunas")->assertStatus(422);
    }

    public function test_update_uang_jalan_ditolak_setelah_lunas(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $trip = $this->makeTripSelesai(200000);

        $resUbah = $this->putJson("/api/v1/trip/{$trip->id_trip}/uang-jalan", ['uang_jalan_alokasi' => 250000]);
        $resUbah->assertStatus(200)->assertJsonPath('data.uang_jalan_alokasi', 250000);

        $this->postJson("/api/v1/trip/{$trip->id_trip}/settlement/lunas")->assertStatus(200);

        $resTolak = $this->putJson("/api/v1/trip/{$trip->id_trip}/uang-jalan", ['uang_jalan_alokasi' => 300000]);
        $resTolak->assertStatus(422);
    }
}
