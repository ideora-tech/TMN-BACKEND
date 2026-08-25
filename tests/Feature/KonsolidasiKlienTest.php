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

class KonsolidasiKlienTest extends TestCase
{
    use RefreshDatabase;

    private string $idKlien;
    private string $idJenisKendaraan;
    private string $idRute;

    private function siapkanMaster(): void
    {
        $this->idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $this->idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Konsolidasi', 'dibuat_pada' => now(),
        ]);

        $this->idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);

        $this->idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $this->idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Jakarta - Semarang',
            'asal' => 'Jakarta', 'tujuan' => 'Semarang',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function buatProyek(string $tipeHarga = 'per_rit'): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Konsolidasi Klien',
            'tipe_harga'    => $tipeHarga,
        ]);
    }

    private function buatProyekRute(string $idProyek, string $idRute, ?string $idJenisKendaraan, ?float $harga): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek_rute')->insert([
            'id_proyek_rute'     => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_proyek'          => $idProyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'harga_penawaran'    => $harga,
            'estimasi_ritase'    => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function buatTripUnitOnlyDenganAlokasiInternal(string $idProyek): TripModel
    {
        $idVendor = \App\Modules\Vendor\VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => 'PT Vendor Unit Only',
        ])->id_vendor;

        $kontrak = \App\Modules\KontrakVendor\KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => 'unit_only',
        ]);

        $idArmadaVendor = \App\Modules\ArmadaVendor\ArmadaVendorModel::create([
            'id_vendor'          => $idVendor,
            'nopol'              => 'B 8888 UO',
            'id_jenis_kendaraan' => $this->idJenisKendaraan,
        ])->id_armada_vendor;

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Internal UO', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $idJenisLain = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisLain, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-LAIN', 'nama_jenis' => 'CDD', 'dibuat_pada' => now(),
        ]);
        $this->buatProyekRute($idProyek, $this->idRute, $this->idJenisKendaraan, 1000000);
        $this->buatProyekRute($idProyek, $this->idRute, $idJenisLain, 500000);

        $idArmadaInternal = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaInternal, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B 7777 IN', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $idJenisLain, 'dibuat_pada' => now(),
        ]);
        DB::table('alokasi_armada')->insert([
            'id_alokasi' => (string) Str::uuid(), 'id_proyek' => $idProyek, 'id_supir' => $idSupir,
            'id_armada' => $idArmadaInternal, 'tanggal' => now()->subDay()->toDateString(),
            'sumber' => 'penugasan', 'dibuat_pada' => now(),
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $idProyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir'          => $idSupir,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $this->idRute,
            'waktu_berangkat' => now()->subDay(),
        ]);

        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'selesai']);

        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 60, 'dibuat_pada' => now(),
        ]);

        return $trip;
    }

    private function buatTrip(string $idProyek, bool $denganRute = true, float $jarak = 100, string $status = 'selesai', bool $denganLaporan = true): TripModel
    {
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' KK', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'dibuat_pada' => now(),
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Konsol ' . Str::random(4), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek' => $idProyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $denganRute ? $this->idRute : null,
            'waktu_berangkat' => now()->subDay(),
        ]);

        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => $status]);

        if ($denganLaporan) {
            DB::table('laporan_perjalanan')->insert([
                'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => $jarak, 'dibuat_pada' => now(),
            ]);
        }

        return $trip;
    }

    public function test_rekap_lintas_proyek_dengan_estimasi_dan_tanpa_tarif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyekA = $this->buatProyek();
        $proyekB = $this->buatProyek();
        $this->buatProyekRute($proyekA->id_proyek, $this->idRute, $this->idJenisKendaraan, 1000000);
        $this->buatProyekRute($proyekB->id_proyek, $this->idRute, $this->idJenisKendaraan, 1000000);
        $this->buatTrip($proyekA->id_proyek, true, 120);
        $this->buatTrip($proyekB->id_proyek, true, 80);
        $tanpaTarif = $this->buatTrip($proyekB->id_proyek, false, 50);
        $this->buatTrip($proyekA->id_proyek, true, 999, 'berjalan');
        $this->buatTrip($proyekA->id_proyek, true, 999, 'selesai', false);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200)
            ->assertJsonPath('data.klien.nama_klien', 'Klien Konsolidasi')
            ->assertJsonPath('data.ringkasan.total_rit', 3)
            ->assertJsonPath('data.ringkasan.total_jarak_km', 250)
            ->assertJsonPath('data.ringkasan.estimasi_nilai', 2000000)
            ->assertJsonPath('data.ringkasan.tanpa_tarif', 1);

        $data = collect($res->json('data.trips'))->keyBy('id_trip');
        $this->assertNull($data[$tanpaTarif->id_trip]['tarif']);
        $this->assertFalse($data[$tanpaTarif->id_trip]['sudah_difakturkan']);

        $berTarif = collect($res->json('data.trips'))->firstWhere('tarif', '!=', null);
        $this->assertSame('Jakarta', $berTarif['asal']);
        $this->assertSame('Semarang', $berTarif['tujuan']);
    }

    public function test_trip_unit_only_pakai_armada_vendor_bukan_alokasi_internal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyek = $this->buatProyek();
        $trip = $this->buatTripUnitOnlyDenganAlokasiInternal($proyek->id_proyek);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200);
        $baris = collect($res->json('data.trips'))->firstWhere('id_trip', $trip->id_trip);

        $this->assertSame('B 8888 UO', $baris['nopol']);
        $this->assertSame(1000000.0, (float) $baris['tarif']['harga']);
        $this->assertSame('vendor', $baris['sumber']);

        $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}&sumber=vendor")
            ->assertStatus(200)->assertJsonPath('data.ringkasan.total_rit', 1);
        $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}&sumber=internal")
            ->assertStatus(200)->assertJsonPath('data.ringkasan.total_rit', 0);
    }

    public function test_flag_sudah_difakturkan_mengikuti_penagihan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyek = $this->buatProyek();
        $this->buatProyekRute($proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1000000);
        $trip = $this->buatTrip($proyek->id_proyek);

        $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(201);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200)->assertJsonPath('data.ringkasan.total_rit', 1);
        $this->assertTrue($res->json('data.trips.0.sudah_difakturkan'));
    }

    public function test_klien_perusahaan_lain_404_dan_export_200(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTrip($this->buatProyek()->id_proyek);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idKlienLain = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlienLain, 'id_perusahaan' => $idPerusahaanLain,
            'kode_klien' => 'KLN-X', 'nama_klien' => 'Klien Lain', 'dibuat_pada' => now(),
        ]);

        $this->getJson("/api/konsolidasi-klien?id_klien={$idKlienLain}")->assertStatus(404);
        $this->get("/api/konsolidasi-klien/export/excel?id_klien={$this->idKlien}")->assertStatus(200);
    }

    public function test_filter_id_proyek_menyaring_dan_baris_memuat_id(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $proyekA = $this->buatProyek();
        $proyekB = $this->buatProyek();
        $this->buatTrip($proyekA->id_proyek);
        $this->buatTrip($proyekB->id_proyek);

        $semua = $this->getJson("/api/konsolidasi-klien?id_klien={$proyekA->id_klien}");
        $semua->assertStatus(200);
        $this->assertCount(2, $semua->json('data.trips'));

        $satu = $this->getJson("/api/konsolidasi-klien?id_klien={$proyekA->id_klien}&id_proyek={$proyekA->id_proyek}");
        $satu->assertStatus(200);
        $this->assertCount(1, $satu->json('data.trips'));
        $this->assertSame($proyekA->id_proyek, $satu->json('data.trips.0.id_proyek'));
        $this->assertNotNull($satu->json('data.trips.0.id_rute'));
    }

    public function test_rekap_menyertakan_titik_drop_dan_biaya_tambahan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyek = $this->buatProyek();
        $this->buatProyekRute($proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1000000);
        $trip = $this->buatTrip($proyek->id_proyek, true, 120);

        DB::table('titik_drop_trip')->insert([
            [
                'id_titik_drop' => (string) Str::uuid(), 'id_trip' => $trip->id_trip,
                'urutan' => 1, 'lokasi' => 'JLB', 'dibuat_pada' => now(),
            ],
            [
                'id_titik_drop' => (string) Str::uuid(), 'id_trip' => $trip->id_trip,
                'urutan' => 2, 'lokasi' => 'MRY', 'dibuat_pada' => now(),
            ],
        ]);

        $idLaporan = DB::table('laporan_perjalanan')->where('id_trip', $trip->id_trip)->value('id_laporan');
        DB::table('biaya_tagihan_trip')->insert([
            'id_biaya_tagihan' => (string) Str::uuid(), 'id_laporan' => $idLaporan,
            'nama_biaya' => 'Bongkar Muat', 'nominal' => 150000, 'dibuat_pada' => now(),
        ]);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200)
            ->assertJsonPath('data.trips.0.titik_drop', ['JLB', 'MRY'])
            ->assertJsonPath('data.trips.0.biaya_tambahan', 150000)
            ->assertJsonPath('data.ringkasan.estimasi_nilai', 1150000);
    }

    public function test_harga_fallback_baris_jenis_kendaraan_null_dipakai_saat_tidak_ada_baris_spesifik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyek = $this->buatProyek();
        $this->buatProyekRute($proyek->id_proyek, $this->idRute, null, 800000);
        $trip = $this->buatTrip($proyek->id_proyek, true, 120);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200);

        $baris = collect($res->json('data.trips'))->firstWhere('id_trip', $trip->id_trip);
        $this->assertSame(800000.0, (float) $baris['tarif']['harga']);
        $this->assertFalse($baris['borongan']);
    }

    public function test_rute_tidak_terdaftar_di_proyek_rute_dihitung_tanpa_tarif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyek = $this->buatProyek();
        $trip = $this->buatTrip($proyek->id_proyek, true, 120);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200)->assertJsonPath('data.ringkasan.tanpa_tarif', 1);

        $baris = collect($res->json('data.trips'))->firstWhere('id_trip', $trip->id_trip);
        $this->assertNull($baris['tarif']);
        $this->assertFalse($baris['borongan']);
    }

    public function test_proyek_borongan_ditag_borongan_dan_tidak_dihitung_tanpa_tarif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyekBorongan = $this->buatProyek('borongan');
        $tripBorongan = $this->buatTrip($proyekBorongan->id_proyek, true, 200);
        $idLaporanBorongan = DB::table('laporan_perjalanan')->where('id_trip', $tripBorongan->id_trip)->value('id_laporan');
        DB::table('biaya_tagihan_trip')->insert([
            'id_biaya_tagihan' => (string) Str::uuid(), 'id_laporan' => $idLaporanBorongan,
            'nama_biaya' => 'Bongkar Muat', 'nominal' => 75000, 'dibuat_pada' => now(),
        ]);

        $proyekPerRit = $this->buatProyek();
        $this->buatProyekRute($proyekPerRit->id_proyek, $this->idRute, $this->idJenisKendaraan, 1000000);
        $tripPerRit = $this->buatTrip($proyekPerRit->id_proyek, true, 100);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}");
        $res->assertStatus(200)
            ->assertJsonPath('data.ringkasan.total_rit', 2)
            ->assertJsonPath('data.ringkasan.tanpa_tarif', 0)
            ->assertJsonPath('data.ringkasan.estimasi_nilai', 1000000);

        $data = collect($res->json('data.trips'))->keyBy('id_trip');
        $this->assertTrue($data[$tripBorongan->id_trip]['borongan']);
        $this->assertNull($data[$tripBorongan->id_trip]['tarif']);
        $this->assertSame(75000.0, (float) $data[$tripBorongan->id_trip]['biaya_tambahan']);
        $this->assertFalse($data[$tripPerRit->id_trip]['borongan']);
    }
}
