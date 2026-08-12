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

class PenagihanTripTest extends TestCase
{
    use RefreshDatabase;

    private string $idKlien;
    private string $idJenisKendaraan;
    private string $idRute;
    private ProyekModel $proyek;

    private function siapkanMaster(): void
    {
        $this->idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $this->idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Penagihan', 'dibuat_pada' => now(),
        ]);

        $this->idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);

        $this->idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $this->idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Jakarta - Surabaya',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $this->proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Penagihan',
        ]);
    }

    private function buatTarif(float $harga, ?string $idKlien = null): void
    {
        DB::table('tarif_rute')->insert([
            'id_tarif_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_rute' => $this->idRute, 'id_jenis_kendaraan' => $this->idJenisKendaraan,
            'id_klien' => $idKlien, 'harga' => $harga,
            'tanggal_mulai' => now()->subMonth()->toDateString(), 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function buatTrip(string $status = 'selesai', bool $denganLaporan = true, ?string $idRute = null): TripModel
    {
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' PT', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'dibuat_pada' => now(),
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Tagih ' . Str::random(4), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $penugasan = PenugasanModel::create([
            'id_proyek' => $this->proyek->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $idRute ?? $this->idRute,
            'waktu_berangkat' => now()->subDay(),
        ]);

        $trip = TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);

        if ($denganLaporan) {
            DB::table('laporan_perjalanan')->insert([
                'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 750, 'dibuat_pada' => now(),
            ]);
        }

        return $trip;
    }

    private function buatTripArmadaVendor(): TripModel
    {
        $idVendor = \App\Modules\Vendor\VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VEN-' . Str::random(8),
            'nama_vendor'   => 'PT Vendor Tagih',
        ])->id_vendor;

        $kontrak = \App\Modules\KontrakVendor\KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $idVendor,
            'mekanisme'     => 'full',
        ]);

        $idArmadaVendor = \App\Modules\ArmadaVendor\ArmadaVendorModel::create([
            'id_vendor'          => $idVendor,
            'nopol'              => 'B ' . random_int(1000, 9999) . ' AV',
            'id_jenis_kendaraan' => $this->idJenisKendaraan,
        ])->id_armada_vendor;

        $idSupirVendor = \App\Modules\SupirVendor\SupirVendorModel::create([
            'id_vendor' => $idVendor,
            'nama'      => 'Supir Vendor Tagih',
        ])->id_supir_vendor;

        $penugasan = PenugasanModel::create([
            'id_proyek'         => $this->proyek->id_proyek,
            'sumber'            => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $idArmadaVendor,
            'id_supir_vendor'   => $idSupirVendor,
        ]);

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $this->idRute,
            'waktu_berangkat' => now()->subDay(),
        ]);

        $trip = TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => 'selesai',
        ]);

        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 500, 'dibuat_pada' => now(),
        ]);

        return $trip;
    }

    public function test_trip_armada_vendor_ber_jenis_kendaraan_bisa_ditagih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTarif(1500000);

        $trip = $this->buatTripArmadaVendor();

        $res = $this->getJson("/api/v1/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_trip');
        $this->assertTrue($data[$trip->id_trip]['bisa_ditagih']);
        $this->assertSame(1500000.0, (float) $data[$trip->id_trip]['tarif']['harga']);
        $this->assertSame('vendor', $data[$trip->id_trip]['sumber']);

        $this->postJson('/api/v1/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(201);

        $this->assertDatabaseHas('faktur_trip', ['id_trip' => $trip->id_trip]);
    }

    public function test_daftar_hanya_trip_selesai_ber_laporan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTarif(1500000);

        $muncul       = $this->buatTrip('selesai', true);
        $masihJalan   = $this->buatTrip('berjalan', true);
        $tanpaLaporan = $this->buatTrip('selesai', false);

        $res = $this->getJson("/api/v1/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id_trip');
        $this->assertTrue($ids->contains($muncul->id_trip));
        $this->assertFalse($ids->contains($masihJalan->id_trip));
        $this->assertFalse($ids->contains($tanpaLaporan->id_trip));
    }

    public function test_tarif_klien_spesifik_menang_dan_tanpa_tarif_tidak_bisa_ditagih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTarif(1500000);
        $this->buatTarif(1400000, $this->idKlien);

        $tripBertarif = $this->buatTrip();

        $idRuteTanpaTarif = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRuteTanpaTarif, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute Tanpa Tarif',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $tripTanpaTarif = $this->buatTrip('selesai', true, $idRuteTanpaTarif);

        $res = $this->getJson("/api/v1/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_trip');

        $this->assertSame(1400000.0, (float) $data[$tripBertarif->id_trip]['tarif']['harga']);
        $this->assertTrue($data[$tripBertarif->id_trip]['bisa_ditagih']);
        $this->assertNull($data[$tripTanpaTarif->id_trip]['tarif']);
        $this->assertFalse($data[$tripTanpaTarif->id_trip]['bisa_ditagih']);
    }

    public function test_generate_draft_faktur_dari_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTarif(1500000);

        $trip1 = $this->buatTrip();
        $trip2 = $this->buatTrip();

        $res = $this->postJson('/api/v1/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip1->id_trip, $trip2->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', 3000000);
        $this->assertStringStartsWith('FK-', (string) $res->json('data.nomor_faktur'));

        $idFaktur = $res->json('data.id_faktur');
        $this->assertDatabaseHas('faktur_trip', ['id_faktur' => $idFaktur, 'id_trip' => $trip1->id_trip]);
        $this->assertDatabaseHas('faktur_trip', ['id_faktur' => $idFaktur, 'id_trip' => $trip2->id_trip]);
        $this->assertDatabaseHas('faktur_item', ['id_faktur' => $idFaktur, 'qty' => 2, 'harga_satuan' => 1500000]);

        $daftar = $this->getJson("/api/v1/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $this->assertCount(0, $daftar->json('data'));
    }

    public function test_generate_menolak_trip_tanpa_tarif_atau_sudah_difakturkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTarif(1500000);

        $trip = $this->buatTrip();
        $payload = [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ];

        $this->postJson('/api/v1/penagihan-trip/faktur', $payload)->assertStatus(201);
        $this->postJson('/api/v1/penagihan-trip/faktur', $payload)->assertStatus(422);
        $this->assertSame(1, DB::table('faktur')->whereNull('dihapus_pada')->count());

        $idRuteTanpaTarif = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRuteTanpaTarif, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute Kosong',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $tripTanpaTarif = $this->buatTrip('selesai', true, $idRuteTanpaTarif);

        $this->postJson('/api/v1/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$tripTanpaTarif->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_faktur_batal_membuka_trip_lagi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatTarif(1500000);

        $trip = $this->buatTrip();

        $idFaktur = $this->postJson('/api/v1/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->json('data.id_faktur');

        $this->assertCount(0, $this->getJson("/api/v1/penagihan-trip?id_proyek={$this->proyek->id_proyek}")->json('data'));

        $this->patchJson("/api/v1/faktur/{$idFaktur}/status", ['status' => 'batal'])->assertStatus(200);

        $daftar = $this->getJson("/api/v1/penagihan-trip?id_proyek={$this->proyek->id_proyek}")->json('data');
        $this->assertCount(1, $daftar);
        $this->assertSame($trip->id_trip, $daftar[0]['id_trip']);
    }
}
