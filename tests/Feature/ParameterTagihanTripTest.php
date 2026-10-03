<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KonsolidasiKlien\Exports\KonsolidasiKlienExport;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParameterTagihanTripTest extends TestCase
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
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Parameter', 'dibuat_pada' => now(),
        ]);

        $this->idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'JK-' . Str::random(6), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);

        $this->idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $this->idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Jakarta - Bandung',
            'asal' => 'Jakarta', 'tujuan' => 'Bandung', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function buatProyek(string $tipeHarga = 'per_rit', array $tarif = [
        'biaya_overnight' => 300000, 'biaya_add_drop' => 150000,
        'biaya_cross_cluster' => 200000, 'biaya_cancellation' => 1000000,
    ]): ProyekModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Parameter',
            'tipe_harga'    => $tipeHarga,
        ]);

        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $this->idRute,
            'id_jenis_kendaraan' => $this->idJenisKendaraan, 'harga_penawaran' => 2500000,
            'estimasi_ritase' => 1, 'dibuat_pada' => now(),
        ]);

        $this->buatPenawaran($proyek->id_proyek, $tarif);

        return $proyek;
    }

    private function buatPenawaran(string $idProyek, array $tarif, ?\DateTimeInterface $dibuat = null): void
    {
        DB::table('penawaran')->insert(array_merge([
            'id_penawaran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $this->idKlien,
            'nomor_penawaran' => 'PNW-' . Str::random(6), 'judul' => 'Penawaran', 'status' => 'disetujui',
            'tipe_harga' => 'per_rit', 'id_proyek' => $idProyek, 'aktif' => 1, 'dibuat_pada' => $dibuat ?? now(),
        ], $tarif));
    }

    private function buatTrip(string $idProyek, string $status = 'selesai', bool $denganLaporan = true): TripModel
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
            'nama' => 'Supir Param ' . Str::random(4), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        $penugasan = PenugasanModel::create(['id_proyek' => $idProyek, 'id_armada' => $idArmada, 'id_supir' => $idSupir]);
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan, 'id_rute' => $this->idRute, 'waktu_berangkat' => now()->subDay(),
        ]);
        $trip = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => $status]);

        if ($denganLaporan) {
            DB::table('laporan_perjalanan')->insert([
                'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_trip' => $trip->id_trip, 'jarak_tempuh_km' => 150, 'dibuat_pada' => now(),
            ]);
        }

        return $trip;
    }

    private function payload(array $ubah = []): array
    {
        return array_merge([
            'jumlah_overnight' => 0, 'jumlah_add_drop' => 0,
            'cross_cluster' => false, 'cancellation' => false, 'keterangan' => null,
        ], $ubah);
    }

    public function test_detail_menampilkan_harga_deal_dan_tarif_dari_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $trip = $this->buatTrip($this->buatProyek()->id_proyek);

        $this->getJson("/api/trip/{$trip->id_trip}/parameter-tagihan")
            ->assertOk()
            ->assertJsonPath('data.berlaku', true)
            ->assertJsonPath('data.mode', 'normal')
            ->assertJsonPath('data.bisa_diatur', true)
            ->assertJsonPath('data.harga_deal', 2500000)
            ->assertJsonPath('data.tarif.overnight', 300000)
            ->assertJsonPath('data.tarif.cancellation', 1000000)
            ->assertJsonPath('data.total_tagihan', 2500000);
    }

    public function test_simpan_parameter_menghitung_total_dan_mengunci_tarif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $proyek = $this->buatProyek();
        $trip = $this->buatTrip($proyek->id_proyek);

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 2, 'jumlah_add_drop' => 1, 'cross_cluster' => true,
            'keterangan' => 'Gudang klien tutup',
        ]))->assertOk()
            ->assertJsonPath('data.rincian.total_parameter', 950000)
            ->assertJsonPath('data.total_tagihan', 3450000)
            ->assertJsonCount(3, 'data.rincian.komponen')
            ->assertJsonPath('data.rincian.komponen.0.label', 'Overnight')
            ->assertJsonPath('data.rincian.komponen.0.nominal', 600000);

        $this->buatPenawaran($proyek->id_proyek, ['biaya_overnight' => 999000, 'biaya_add_drop' => 150000], now()->addMinute());

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 3, 'jumlah_add_drop' => 1, 'cross_cluster' => true,
            'keterangan' => 'Tambah semalam',
        ]))->assertOk()
            ->assertJsonPath('data.tarif.overnight', 300000)
            ->assertJsonPath('data.rincian.komponen.0.nominal', 900000);

        $this->assertDatabaseHas('parameter_tagihan_trip', [
            'id_trip' => $trip->id_trip, 'jumlah_overnight' => 3, 'tarif_overnight' => 300000,
        ]);
    }

    public function test_keterangan_wajib_dan_tarif_kosong_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $trip = $this->buatTrip($this->buatProyek('per_rit', ['biaya_overnight' => 300000])->id_proyek);

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload(['jumlah_overnight' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keterangan wajib diisi saat ada parameter tagihan');

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'cross_cluster' => true, 'keterangan' => 'Lintas cluster',
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'Tarif Cross Cluster belum diatur di penawaran proyek');

        $this->assertDatabaseCount('parameter_tagihan_trip', 0);
    }

    public function test_proyek_borongan_dan_trip_belum_mulai_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();

        $tripBorongan = $this->buatTrip($this->buatProyek('borongan')->id_proyek);
        $this->getJson("/api/trip/{$tripBorongan->id_trip}/parameter-tagihan")
            ->assertOk()->assertJsonPath('data.berlaku', false)->assertJsonPath('data.bisa_diatur', false);
        $this->putJson("/api/trip/{$tripBorongan->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 1, 'keterangan' => 'x',
        ]))->assertStatus(422)->assertJsonPath('message', 'Parameter tagihan hanya berlaku untuk proyek per rit');

        $tripBaru = $this->buatTrip($this->buatProyek()->id_proyek, 'belum_mulai', false);
        $this->putJson("/api/trip/{$tripBaru->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 1, 'keterangan' => 'x',
        ]))->assertStatus(422)->assertJsonPath('message', 'Parameter tagihan bisa diatur setelah trip berjalan');
    }

    public function test_trip_dibatalkan_hanya_cancellation_dan_parameter_lain_dinolkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $trip = $this->buatTrip($this->buatProyek()->id_proyek, 'dibatalkan', false);

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 2, 'cancellation' => true, 'keterangan' => 'Barang klien tidak siap',
        ]))->assertOk()
            ->assertJsonPath('data.mode', 'cancellation')
            ->assertJsonPath('data.rincian.cancellation', true)
            ->assertJsonPath('data.rincian.harga_dasar', 0)
            ->assertJsonPath('data.total_tagihan', 1000000)
            ->assertJsonCount(1, 'data.rincian.komponen');

        $this->assertDatabaseHas('parameter_tagihan_trip', [
            'id_trip' => $trip->id_trip, 'jumlah_overnight' => 0, 'cancellation' => 1, 'tarif_cancellation' => 1000000,
        ]);
    }

    public function test_trip_dan_penugasan_bertanda_cancellation_tidak_bisa_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $trip = $this->buatTrip($this->buatProyek()->id_proyek, 'dibatalkan', false);
        $idPenugasan = DB::table('jadwal_keberangkatan')->where('id_jadwal', $trip->id_jadwal)->value('id_penugasan');

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'cancellation' => true, 'keterangan' => 'Batal klien',
        ]))->assertOk();

        $this->deleteJson("/api/trip/{$trip->id_trip}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Trip ditandai Cancellation untuk ditagih — hapus tandanya di Parameter Tagihan terlebih dahulu');
        $this->deleteJson("/api/penugasan/{$idPenugasan}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Penugasan punya trip yang ditagih biaya cancellation — jejaknya dipakai penagihan, tidak bisa dihapus');

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload())->assertOk();
        $this->deleteJson("/api/trip/{$trip->id_trip}")->assertOk();
    }

    public function test_trip_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $trip = $this->buatTrip($this->buatProyek()->id_proyek);
        DB::table('proyek')->update(['id_perusahaan' => (string) Str::uuid()]);

        $this->getJson("/api/trip/{$trip->id_trip}/parameter-tagihan")->assertStatus(404);
        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload())->assertStatus(404);
    }

    public function test_peran_dengan_izin_ubah_faktur_boleh_mengatur(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->siapkanMaster();
        $trip = $this->buatTrip($this->buatProyek()->id_proyek);

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload())->assertStatus(403);

        $idMenu = (string) Str::uuid();
        DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => 'Invoice', 'path' => '/faktur', 'aktif' => 1, 'dibuat_pada' => now()]);
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'kode_peran' => 'KEUANGAN', 'id_menu' => $idMenu,
            'aksi' => 'ubah', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_add_drop' => 2, 'keterangan' => 'Koreksi keuangan',
        ]))->assertOk()->assertJsonPath('data.rincian.total_parameter', 300000);
    }

    public function test_konsolidasi_menjumlahkan_parameter_dan_menyertakan_trip_cancellation(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $proyek = $this->buatProyek();

        $tripNormal = $this->buatTrip($proyek->id_proyek);
        $this->putJson("/api/trip/{$tripNormal->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 1, 'keterangan' => 'Menginap',
        ]))->assertOk();
        $idLaporan = DB::table('laporan_perjalanan')->where('id_trip', $tripNormal->id_trip)->value('id_laporan');
        DB::table('biaya_tagihan_trip')->insert([
            'id_biaya_tagihan' => (string) Str::uuid(), 'id_laporan' => $idLaporan,
            'nama_biaya' => 'TKBM', 'nominal' => 100000, 'dibuat_pada' => now(),
        ]);

        $tripBatal = $this->buatTrip($proyek->id_proyek, 'dibatalkan', false);
        $this->putJson("/api/trip/{$tripBatal->id_trip}/parameter-tagihan", $this->payload([
            'cancellation' => true, 'keterangan' => 'Dibatalkan klien',
        ]))->assertOk();

        $this->buatTrip($proyek->id_proyek, 'dibatalkan', false);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}")->assertOk();
        $trips = collect($res->json('data.trips'))->keyBy('id_trip');

        $this->assertCount(2, $trips);
        $this->assertEquals(2900000, $trips[$tripNormal->id_trip]['total_tagihan']);
        $this->assertEquals(300000, $trips[$tripNormal->id_trip]['parameter']['total']);
        $this->assertEquals(100000, $trips[$tripNormal->id_trip]['biaya_tambahan']);
        $this->assertSame('dibatalkan', $trips[$tripBatal->id_trip]['status']);
        $this->assertTrue($trips[$tripBatal->id_trip]['parameter']['cancellation']);
        $this->assertEquals(0, $trips[$tripBatal->id_trip]['harga_dasar']);
        $this->assertEquals(1000000, $trips[$tripBatal->id_trip]['total_tagihan']);
        $this->assertTrue($trips[$tripBatal->id_trip]['bisa_ditagih']);
        $res->assertJsonPath('data.ringkasan.estimasi_nilai', 3900000)
            ->assertJsonPath('data.ringkasan.tanpa_tarif', 0);

        $this->getJson('/api/konsolidasi-klien/siap-tagih')
            ->assertOk()
            ->assertJsonPath('data.0.jumlah_trip', 2);

        $export = new KonsolidasiKlienExport('Klien Parameter', 'Periode', collect($res->json('data.trips')));
        $heading = $export->headings()[1];
        $this->assertContains('Overnight', $heading);
        $this->assertContains('Cancellation', $heading);
        $this->assertContains('TKBM', $heading);
        $baris = collect($export->array())->first(fn ($r) => in_array(1000000.0, $r, true) || in_array(1000000, $r, true));
        $this->assertNotNull($baris);
    }

    public function test_excel_tidak_menjumlahkan_parameter_trip_tanpa_tarif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $proyek = $this->buatProyek();
        DB::table('proyek_rute')->where('id_proyek', $proyek->id_proyek)->update(['harga_penawaran' => null]);
        $trip = $this->buatTrip($proyek->id_proyek);

        $this->putJson("/api/trip/{$trip->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_overnight' => 2, 'keterangan' => 'Menginap',
        ]))->assertOk()->assertJsonPath('data.total_tagihan', null);

        $res = $this->getJson("/api/konsolidasi-klien?id_klien={$this->idKlien}")->assertOk()
            ->assertJsonPath('data.trips.0.bisa_ditagih', false)
            ->assertJsonPath('data.ringkasan.estimasi_nilai', 0)
            ->assertJsonPath('data.ringkasan.tanpa_tarif', 1);

        $rows = (new KonsolidasiKlienExport('Klien', 'Periode', collect($res->json('data.trips'))))->array();
        $baris = $rows[count($rows) - 4];
        $this->assertSame('TOTAL', $baris[count($baris) - 2]);
        $this->assertEquals(0, $baris[count($baris) - 1]);
    }

    public function test_faktur_menjumlahkan_parameter_dan_parameter_terkunci_setelah_masuk_invoice(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->siapkanMaster();
        $proyek = $this->buatProyek();

        $tripNormal = $this->buatTrip($proyek->id_proyek);
        $this->putJson("/api/trip/{$tripNormal->id_trip}/parameter-tagihan", $this->payload([
            'jumlah_add_drop' => 2, 'cross_cluster' => true, 'keterangan' => 'Multidrop lintas cluster',
        ]))->assertOk();

        $tripBatal = $this->buatTrip($proyek->id_proyek, 'dibatalkan', false);
        $this->putJson("/api/trip/{$tripBatal->id_trip}/parameter-tagihan", $this->payload([
            'cancellation' => true, 'keterangan' => 'Batal klien',
        ]))->assertOk();

        $daftar = collect($this->getJson("/api/penagihan-trip?id_proyek={$proyek->id_proyek}")->assertOk()->json('data'))->keyBy('id_trip');
        $this->assertTrue($daftar[$tripBatal->id_trip]['bisa_ditagih']);
        $this->assertEquals(500000, $daftar[$tripNormal->id_trip]['parameter']['total']);

        $idFaktur = $this->postJson('/api/penagihan-trip/faktur', [
            'id_proyek' => $proyek->id_proyek,
            'trip_ids' => [$tripNormal->id_trip, $tripBatal->id_trip],
            'tanggal_faktur' => now()->toDateString(),
        ])->assertStatus(201)->json('data.id_faktur');

        $item = DB::table('faktur_item')->where('id_faktur', $idFaktur)->first();
        $this->assertSame(4000000.0, (float) $item->harga_satuan);
        $this->assertStringContainsString('1 rit + 1 cancellation', $item->deskripsi);

        $this->putJson("/api/trip/{$tripNormal->id_trip}/parameter-tagihan", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Trip sudah masuk invoice — parameter tagihan tidak dapat diubah');
        $this->getJson("/api/trip/{$tripNormal->id_trip}/parameter-tagihan")
            ->assertOk()->assertJsonPath('data.terkunci', true)->assertJsonPath('data.bisa_diatur', false);
        $this->deleteJson("/api/trip/{$tripNormal->id_trip}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Trip sudah masuk invoice — tidak dapat dihapus');
    }
}
