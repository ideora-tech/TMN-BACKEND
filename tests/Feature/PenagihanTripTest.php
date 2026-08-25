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

    private function buatTripJenisLain(string $idJenisKendaraan, ?string $idRute = null): TripModel
    {
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' PT', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $idJenisKendaraan, 'dibuat_pada' => now(),
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
            'status'    => 'selesai',
        ]);

        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 750, 'dibuat_pada' => now(),
        ]);

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
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $trip = $this->buatTripArmadaVendor();

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_trip');
        $this->assertTrue($data[$trip->id_trip]['bisa_ditagih']);
        $this->assertSame(1500000.0, (float) $data[$trip->id_trip]['tarif']['harga']);
        $this->assertSame('vendor', $data[$trip->id_trip]['sumber']);

        $this->postJson('/api/penagihan-trip/faktur', [
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
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $muncul       = $this->buatTrip('selesai', true);
        $masihJalan   = $this->buatTrip('berjalan', true);
        $tanpaLaporan = $this->buatTrip('selesai', false);

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id_trip');
        $this->assertTrue($ids->contains($muncul->id_trip));
        $this->assertFalse($ids->contains($masihJalan->id_trip));
        $this->assertFalse($ids->contains($tanpaLaporan->id_trip));
    }

    public function test_harga_jenis_kendaraan_cocok_menang_dan_rute_tak_terdaftar_tidak_bisa_ditagih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, null, 1500000);
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1400000);

        $tripBertarif = $this->buatTrip();

        $idRuteTanpaTarif = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRuteTanpaTarif, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute Tanpa Tarif',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $tripTanpaTarif = $this->buatTrip('selesai', true, $idRuteTanpaTarif);

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);
        $data = collect($res->json('data'))->keyBy('id_trip');

        $this->assertSame(1400000.0, (float) $data[$tripBertarif->id_trip]['tarif']['harga']);
        $this->assertTrue($data[$tripBertarif->id_trip]['bisa_ditagih']);
        $this->assertNull($data[$tripTanpaTarif->id_trip]['tarif']);
        $this->assertFalse($data[$tripTanpaTarif->id_trip]['bisa_ditagih']);
    }

    public function test_harga_fallback_baris_jenis_kendaraan_null_dipakai_saat_tidak_ada_baris_spesifik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, null, 1300000);

        $trip = $this->buatTrip();

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);
        $baris = collect($res->json('data'))->firstWhere('id_trip', $trip->id_trip);

        $this->assertSame(1300000.0, (float) $baris['tarif']['harga']);
        $this->assertTrue($baris['bisa_ditagih']);
        $this->assertFalse($baris['tarif']['perkiraan']);
    }

    public function test_harga_fallback_termurah_dipakai_saat_jenis_kendaraan_tidak_cocok_baris_manapun(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $idJenisPickup = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisPickup, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'Pickup', 'dibuat_pada' => now(),
        ]);
        $idJenisColtDiesel = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisColtDiesel, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'Colt Diesel', 'dibuat_pada' => now(),
        ]);

        // Rate card rute ini cuma punya tarif untuk Tronton & Pickup — tidak ada baris "semua jenis".
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $idJenisPickup, 800000);

        // Ops assign armada Colt Diesel — tidak cocok baris manapun.
        $trip = $this->buatTripJenisLain($idJenisColtDiesel);

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $res->assertStatus(200);
        $baris = collect($res->json('data'))->firstWhere('id_trip', $trip->id_trip);

        $this->assertTrue($baris['bisa_ditagih']);
        $this->assertSame(800000.0, (float) $baris['tarif']['harga']);
        $this->assertTrue($baris['tarif']['perkiraan']);

        // Tidak lagi macet — draft faktur berhasil dibuat pakai tarif termurah itu.
        $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(201)->assertJsonPath('data.total', 800000);
    }

    public function test_harga_tarif_perkiraan_false_saat_cocok_persis_atau_fallback_semua_jenis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1400000);

        $tripCocokPersis = $this->buatTrip();

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $baris = collect($res->json('data'))->firstWhere('id_trip', $tripCocokPersis->id_trip);
        $this->assertFalse($baris['tarif']['perkiraan']);
    }

    public function test_generate_draft_faktur_dari_trip(): void
    {
        $pengguna = $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $trip1 = $this->buatTrip();
        $trip2 = $this->buatTrip();

        $res = $this->postJson('/api/penagihan-trip/faktur', [
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
        $this->assertSame(1, DB::table('faktur_item')->where('id_faktur', $idFaktur)->count());
        $item = DB::table('faktur_item')->where('id_faktur', $idFaktur)->first();
        $this->assertSame(1, (int) $item->qty);
        $this->assertSame(3000000.0, (float) $item->harga_satuan);
        $this->assertStringContainsString('2 rit', $item->deskripsi);

        $daftar = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}");
        $this->assertCount(0, $daftar->json('data'));

        $detail = $this->getJson("/api/faktur/{$idFaktur}");
        $detail->assertStatus(200)->assertJsonCount(2, 'data.trip_terkait');
        $idTripTerkait = collect($detail->json('data.trip_terkait'))->pluck('id_trip')->all();
        $this->assertEqualsCanonicalizing([$trip1->id_trip, $trip2->id_trip], $idTripTerkait);

        $daftarFaktur = $this->getJson('/api/faktur');
        $daftarFaktur->assertStatus(200);
        $baris = collect($daftarFaktur->json('data'))->firstWhere('id_faktur', $idFaktur);
        $this->assertSame($pengguna->username, $baris['dibuat_oleh_nama']);
        $this->assertSame('Proyek Penagihan', $baris['nama_proyek']);
        $this->assertSame('Klien Penagihan', $baris['nama_klien']);
    }

    public function test_draft_faktur_menyimpan_referensi_penawaran_disetujui_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $idPenawaranLama = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idPenawaranLama, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => $this->idKlien, 'id_proyek' => $this->proyek->id_proyek,
            'nomor_penawaran' => 'PNW-' . Str::random(8), 'judul' => 'Penawaran Awal',
            'nilai_penawaran' => 10000000,
            'status' => 'disetujui', 'tipe_harga' => 'per_rit',
            'dibuat_pada' => now()->subDay(),
        ]);
        $idRevisiDisetujui = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran' => $idRevisiDisetujui, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien' => $this->idKlien, 'id_proyek' => $this->proyek->id_proyek,
            'nomor_penawaran' => 'PNW-' . Str::random(8), 'judul' => 'Penawaran Revisi',
            'nilai_penawaran' => 15000000,
            'status' => 'disetujui', 'tipe_harga' => 'per_rit',
            'id_penawaran_induk' => $idPenawaranLama, 'dibuat_pada' => now(),
        ]);

        $trip = $this->buatTrip();

        $res = $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ]);
        $res->assertStatus(201);

        // Faktur merefer ke penawaran disetujui TERBARU (revisi menang atas penawaran awal).
        $this->assertDatabaseHas('faktur', [
            'id_faktur'    => $res->json('data.id_faktur'),
            'id_penawaran' => $idRevisiDisetujui,
        ]);

        // Detail faktur mengembalikan nomor & nilai penawaran + nama proyek/klien.
        $detail = $this->getJson('/api/faktur/' . $res->json('data.id_faktur'));
        $detail->assertStatus(200)
            ->assertJsonPath('data.id_penawaran', $idRevisiDisetujui)
            ->assertJsonPath('data.nama_proyek', 'Proyek Penagihan')
            ->assertJsonPath('data.nama_klien', 'Klien Penagihan');
        $this->assertNotNull($detail->json('data.nomor_penawaran'));
        $this->assertSame(15000000.0, (float) $detail->json('data.nilai_penawaran'));
    }

    public function test_draft_faktur_tanpa_penawaran_disetujui_id_penawaran_null(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $trip = $this->buatTrip();

        $res = $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ]);
        $res->assertStatus(201);

        $this->assertDatabaseHas('faktur', [
            'id_faktur'    => $res->json('data.id_faktur'),
            'id_penawaran' => null,
        ]);
    }

    public function test_generate_menolak_trip_tanpa_tarif_atau_sudah_difakturkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $trip = $this->buatTrip();
        $payload = [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ];

        $this->postJson('/api/penagihan-trip/faktur', $payload)->assertStatus(201);
        $this->postJson('/api/penagihan-trip/faktur', $payload)->assertStatus(422);
        $this->assertSame(1, DB::table('faktur')->whereNull('dihapus_pada')->count());

        $idRuteTanpaTarif = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $idRuteTanpaTarif, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Rute Kosong',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $tripTanpaTarif = $this->buatTrip('selesai', true, $idRuteTanpaTarif);

        $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$tripTanpaTarif->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('message', 'Tarif belum diatur di rute proyek');
    }

    public function test_proyek_borongan_trip_tidak_bisa_ditagih_dan_ditolak_saat_generate_faktur(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $proyekBorongan = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Borongan Penagihan',
            'tipe_harga'    => 'borongan',
        ]);
        $this->buatProyekRute($proyekBorongan->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' BR', 'merk' => 'Hino',
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'dibuat_pada' => now(),
        ]);
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Borongan', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $penugasan = PenugasanModel::create([
            'id_proyek' => $proyekBorongan->id_proyek,
            'id_armada' => $idArmada,
            'id_supir'  => $idSupir,
        ]);
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'id_rute'         => $this->idRute,
            'waktu_berangkat' => now()->subDay(),
        ]);
        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'selesai']);
        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 100, 'dibuat_pada' => now(),
        ]);

        $res = $this->getJson("/api/penagihan-trip?id_proyek={$proyekBorongan->id_proyek}");
        $res->assertStatus(200);
        $baris = collect($res->json('data'))->firstWhere('id_trip', $trip->id_trip);
        $this->assertTrue($baris['borongan']);
        $this->assertNull($baris['tarif']);
        $this->assertFalse($baris['bisa_ditagih']);

        $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $proyekBorongan->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('message', 'Trip proyek borongan difakturkan dari halaman proyek');
    }

    public function test_faktur_batal_membuka_trip_lagi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 1500000);

        $trip = $this->buatTrip();

        $idFaktur = $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->json('data.id_faktur');

        $this->assertCount(0, $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}")->json('data'));

        $this->patchJson("/api/faktur/{$idFaktur}/status", ['status' => 'batal'])->assertStatus(200);

        $daftar = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}")->json('data');
        $this->assertCount(1, $daftar);
        $this->assertSame($trip->id_trip, $daftar[0]['id_trip']);
    }

    public function test_buat_faktur_menyertakan_item_biaya_tagihan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $this->buatProyekRute($this->proyek->id_proyek, $this->idRute, $this->idJenisKendaraan, 900000);

        $trip = $this->buatTrip();

        $idLaporan = DB::table('laporan_perjalanan')->where('id_trip', $trip->id_trip)->value('id_laporan');
        DB::table('biaya_tagihan_trip')->insert([
            'id_biaya_tagihan' => (string) Str::uuid(), 'id_laporan' => $idLaporan,
            'nama_biaya' => 'Multidrop', 'nominal' => 150000, 'dibuat_pada' => now(),
        ]);

        $daftar = $this->getJson("/api/penagihan-trip?id_proyek={$this->proyek->id_proyek}")->json('data');
        $baris = collect($daftar)->firstWhere('id_trip', $trip->id_trip);
        $this->assertSame(150000.0, (float) $baris['total_biaya_tagihan']);
        $this->assertCount(1, $baris['biaya_tagihan']);
        $this->assertSame('Multidrop', $baris['biaya_tagihan'][0]['nama_biaya']);
        $this->assertSame(150000.0, (float) $baris['biaya_tagihan'][0]['nominal']);

        $res = $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek'      => $this->proyek->id_proyek,
            'trip_ids'       => [$trip->id_trip],
            'tanggal_faktur' => now()->toDateString(),
            'keterangan'     => 'Jasa Angkutan Unit Dedicated Project Astro Cibitung Periode Juli 2026',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.total', 1050000);

        $idFaktur = $res->json('data.id_faktur');
        $this->assertSame(1, DB::table('faktur_item')->where('id_faktur', $idFaktur)->count());
        $item = DB::table('faktur_item')->where('id_faktur', $idFaktur)->first();
        $this->assertSame(1050000.0, (float) $item->harga_satuan);
        $this->assertSame('Jasa Angkutan Unit Dedicated Project Astro Cibitung Periode Juli 2026', $item->deskripsi);
    }
}
