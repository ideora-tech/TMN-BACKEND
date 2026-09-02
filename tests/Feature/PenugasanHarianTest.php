<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanHarianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();

        $idApprover = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna' => $idApprover, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'MANAGER', 'username' => 'approver_default_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('x'),
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengajuan_pengeluaran', 'nama' => 'Pengajuan Pengeluaran', 'mode_resolusi' => 'pinned',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
            'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
        ]);
    }

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Penugasan Harian',
        ]);
    }

    private function makeSupir(string $nama = 'Budi', string $status = 'aktif', ?string $idArmadaDefault = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'status' => $status, 'id_armada_default' => $idArmadaDefault,
            'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeTripUntukPenugasan(string $idPenugasan, string $status): TripModel
    {
        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $idPenugasan,
            'waktu_berangkat' => now(),
        ]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal,
            'status'    => $status,
        ]);
    }

    private function makeRute(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Jakarta - Bandung',
            'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyekRute(string $idProyek, string $idRute, ?float $uangJalan, ?string $idJenis = null): void
    {
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute,
            'id_jenis_kendaraan' => $idJenis, 'uang_jalan' => $uangJalan,
            'dibuat_pada' => now(),
        ]);
    }

    private function makeArmada(): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . strtoupper(Str::random(3)),
            'merk'          => 'Hino',
        ]);
    }

    private function makeVendorUnitOnly(): array
    {
        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Unit Only Harian',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => 'unit_only',
            'status'        => 'aktif',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'nopol'             => 'B ' . random_int(1000, 9999) . ' VD',
        ]);

        return [
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
        ];
    }

    public function test_aktivitas_board_berubah_saat_trip_diubah_dari_mobile(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $penugasan = \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $this->makeSupir(),
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
            'dibuat_pada'   => now()->subMinutes(10),
        ]);

        $sebelum = $this->getJson('/api/penugasan/board/aktivitas')
            ->assertStatus(200)
            ->json('data.terakhir');
        $this->assertNotNull($sebelum);

        $trip = $this->makeTripUntukPenugasan($penugasan->id_penugasan, 'berjalan');
        \Illuminate\Support\Facades\DB::table('trip')
            ->where('id_trip', $trip->id_trip)
            ->update(['diubah_pada' => now()->addMinutes(5)]);

        $sesudah = $this->getJson('/api/penugasan/board/aktivitas')->json('data.terakhir');
        $this->assertNotSame($sebelum, $sesudah);
        $this->assertGreaterThan($sebelum, $sesudah);
    }

    private function makeVendorPaket(string $mekanisme = 'unit_driver'): array
    {
        $vendor = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Paket Harian',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_vendor'     => $vendor->id_vendor,
            'mekanisme'     => $mekanisme,
            'status'        => 'aktif',
        ]);
        $armadaVendor = ArmadaVendorModel::create([
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'nopol'             => 'B ' . random_int(1000, 9999) . ' PK',
        ]);
        $idSupirVendor = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $idSupirVendor,
            'id_vendor'       => $vendor->id_vendor,
            'nama'            => 'Supir Vendor Paket',
            'dibuat_pada'     => now(),
        ]);

        return [
            'id_vendor'         => $vendor->id_vendor,
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor,
            'id_armada_vendor'  => $armadaVendor->id_armada_vendor,
            'id_supir_vendor'   => $idSupirVendor,
        ];
    }

    public function test_assign_unit_paket_vendor_dengan_supir_vendor_berhasil_tanpa_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null, null);
        $paket = $this->makeVendorPaket();

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-01',
            'id_armada_vendor' => $paket['id_armada_vendor'],
            'id_supir_vendor'  => $paket['id_supir_vendor'],
            'id_proyek'        => $proyek->id_proyek,
            'id_rute'          => $rute,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('penugasan', [
            'id_armada_vendor' => $paket['id_armada_vendor'],
            'id_supir_vendor'  => $paket['id_supir_vendor'],
            'id_kontrak_vendor' => $paket['id_kontrak_vendor'],
            'sumber'           => 'vendor',
            'id_supir'         => null,
        ]);
        $this->assertDatabaseCount('pengajuan_pengeluaran', 0);
    }

    public function test_assign_unit_paket_tanpa_supir_vendor_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null, null);
        $paket = $this->makeVendorPaket();

        $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-01',
            'id_armada_vendor' => $paket['id_armada_vendor'],
            'id_supir'         => $this->makeSupir(),
            'id_proyek'        => $proyek->id_proyek,
            'id_rute'          => $rute,
        ])->assertStatus(422);
    }

    public function test_assign_unit_paket_supir_vendor_lain_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null, null);
        $paket = $this->makeVendorPaket();
        $lain  = $this->makeVendorPaket();

        $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-01',
            'id_armada_vendor' => $paket['id_armada_vendor'],
            'id_supir_vendor'  => $lain['id_supir_vendor'],
            'id_proyek'        => $proyek->id_proyek,
            'id_rute'          => $rute,
        ])->assertStatus(422);
    }

    public function test_board_menyertakan_unit_paket_dengan_mekanisme(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $paket = $this->makeVendorPaket('full');

        $res = $this->getJson('/api/penugasan/board?dari=2026-09-01&sampai=2026-09-02')
            ->assertStatus(200);

        $unit = collect($res->json('data.units'))
            ->firstWhere('id_armada_vendor', $paket['id_armada_vendor']);
        $this->assertNotNull($unit);
        $this->assertSame('full', $unit['mekanisme']);
        $this->assertSame('vendor', $unit['tipe']);
    }

    public function test_board_menyertakan_status_kontrak_unit_vendor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $paket = $this->makeVendorPaket('unit_only');
        DB::table('kontrak_vendor')
            ->where('id_kontrak_vendor', $paket['id_kontrak_vendor'])
            ->update(['status' => 'draft']);

        $res = $this->getJson('/api/penugasan/board?dari=2026-09-01&sampai=2026-09-02')
            ->assertStatus(200);

        $unit = collect($res->json('data.units'))
            ->firstWhere('id_armada_vendor', $paket['id_armada_vendor']);
        $this->assertNotNull($unit);
        $this->assertSame('draft', $unit['status_kontrak']);
        $this->assertTrue($unit['kontrak_habis']);
    }

    public function test_assign_harian_menyimpan_titik_drop_dengan_uang_jalan_tambahan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir();

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'    => '2026-09-01',
            'id_armada'  => $armada->id_armada,
            'id_supir'   => $supir,
            'id_proyek'  => $proyek->id_proyek,
            'id_rute'    => $rute,
            'titik_drop' => [
                ['lokasi' => 'Gudang A', 'uang_jalan_tambahan' => 50000],
                ['lokasi' => 'Gudang B'],
            ],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $idPenugasan = $res->json('data.penugasan.0.id_penugasan');
        $rows = DB::table('titik_drop_penugasan')
            ->where('id_penugasan', $idPenugasan)->whereNull('dihapus_pada')->orderBy('urutan')
            ->get(['lokasi', 'uang_jalan_tambahan']);

        $this->assertSame('Gudang A', $rows[0]->lokasi);
        $this->assertEquals(50000.0, (float) $rows[0]->uang_jalan_tambahan);
        $this->assertSame('Gudang B', $rows[1]->lokasi);
        $this->assertEquals(0.0, (float) $rows[1]->uang_jalan_tambahan);
    }

    public function test_assign_harian_titik_drop_tanpa_lokasi_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir();

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'    => '2026-09-01',
            'id_armada'  => $armada->id_armada,
            'id_supir'   => $supir,
            'id_proyek'  => $proyek->id_proyek,
            'id_rute'    => $rute,
            'titik_drop' => [['uang_jalan_tambahan' => 50000]],
        ]);

        $res->assertStatus(422);
    }

    public function test_assign_rentang_membuat_baris_per_tanggal_dan_satu_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir('Budi Harian');

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'        => '2026-09-01',
            'tanggal_sampai' => '2026-09-03',
            'id_armada'      => $armada->id_armada,
            'id_supir'       => $supir,
            'id_proyek'      => $proyek->id_proyek,
            'id_rute'        => $rute,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 3)->assertJsonPath('data.peringatan', []);

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $tanggal) {
            $this->assertDatabaseHas('penugasan', [
                'id_armada'      => $armada->id_armada,
                'id_supir'       => $supir,
                'tanggal_tugas'  => $tanggal,
                'estimasi_biaya' => 150000,
            ]);
        }

        $this->assertSame(1, DB::table('pengajuan_pengeluaran')->where('kategori', 'uang_jalan')->count());
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_supir' => $supir, 'id_proyek' => $proyek->id_proyek,
            'kategori' => 'uang_jalan', 'nominal' => 450000, 'tarif_per_hari' => 150000,
            'periode_dari' => '2026-09-01', 'periode_sampai' => '2026-09-03', 'status' => 'menunggu_approval',
        ]);

        $idPengajuan = DB::table('pengajuan_pengeluaran')->where('id_supir', $supir)->value('id_pengajuan');
        $this->assertSame(3, DB::table('penugasan')->where('id_supir', $supir)->where('id_pengajuan', $idPengajuan)->count());
    }

    public function test_guard_supir_dobel_tanggal_unit_boleh_dobel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek  = $this->makeProyek();
        $rute    = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armadaA = $this->makeArmada();
        $armadaB = $this->makeArmada();
        $supirA  = $this->makeSupir('Andi Dobel');
        $supirB  = $this->makeSupir('Cici Dobel');

        \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaA->id_armada,
            'id_supir'      => $supirA,
            'tanggal_tugas' => '2026-09-10',
            'status'        => 'aktif',
        ]);

        $resUnit = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-10',
            'id_armada' => $armadaA->id_armada,
            'id_supir'  => $supirB,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $rute,
        ]);
        $resUnit->assertStatus(200)->assertJsonPath('data.sukses', 1);
        $this->assertCount(0, $resUnit->json('data.gagal'));
        $this->assertSame(2, DB::table('penugasan')
            ->where('id_armada', $armadaA->id_armada)
            ->where('tanggal_tugas', '2026-09-10')
            ->count());

        $resSupir = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-10',
            'id_armada' => $armadaB->id_armada,
            'id_supir'  => $supirA,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $rute,
        ]);
        $resSupir->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertCount(1, $resSupir->json('data.gagal'));
        $this->assertStringContainsString('Supir', $resSupir->json('data.gagal.0.alasan'));
    }

    public function test_supir_boleh_dobel_tanggal_sama_jika_proyek_berbeda(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $rute    = $this->makeRute();
        $this->makeProyekRute($proyekA->id_proyek, $rute, null);
        $this->makeProyekRute($proyekB->id_proyek, $rute, null);
        $armadaA = $this->makeArmada();
        $armadaB = $this->makeArmada();
        $supir   = $this->makeSupir('Dedi Lintas Proyek');

        $resA = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-12',
            'id_armada' => $armadaA->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyekA->id_proyek,
            'id_rute'   => $rute,
        ]);
        $resA->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $resB = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-12',
            'id_armada' => $armadaB->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyekB->id_proyek,
            'id_rute'   => $rute,
        ]);
        $resB->assertStatus(200)->assertJsonPath('data.sukses', 1);
        $this->assertCount(0, $resB->json('data.gagal'));

        $this->assertSame(2, DB::table('penugasan')
            ->where('id_supir', $supir)
            ->where('tanggal_tugas', '2026-09-12')
            ->count());
    }

    public function test_supir_boleh_dobel_tanggal_sama_proyek_sama_jika_rute_berbeda(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek  = $this->makeProyek();
        $ruteA   = $this->makeRute();
        $ruteB   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $ruteA, null);
        $this->makeProyekRute($proyek->id_proyek, $ruteB, null);
        $armadaA = $this->makeArmada();
        $armadaB = $this->makeArmada();
        $supir   = $this->makeSupir('Eka Lintas Rute');

        $resA = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-13',
            'id_armada' => $armadaA->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $ruteA,
        ]);
        $resA->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $resB = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-13',
            'id_armada' => $armadaB->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $ruteB,
        ]);
        $resB->assertStatus(200)->assertJsonPath('data.sukses', 1);
        $this->assertCount(0, $resB->json('data.gagal'));

        $this->assertSame(2, DB::table('penugasan')
            ->where('id_supir', $supir)
            ->where('tanggal_tugas', '2026-09-13')
            ->count());
    }

    public function test_assign_unit_yang_sama_ke_dua_proyek_berbeda_tanggal_sama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $rute    = $this->makeRute();
        $this->makeProyekRute($proyekA->id_proyek, $rute, null);
        $this->makeProyekRute($proyekB->id_proyek, $rute, null);
        $armada = $this->makeArmada();
        $supirA = $this->makeSupir('Rian Dobel Proyek');
        $supirB = $this->makeSupir('Sari Dobel Proyek');

        $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-25',
            'id_armada' => $armada->id_armada,
            'id_supir'  => $supirA,
            'id_proyek' => $proyekA->id_proyek,
            'id_rute'   => $rute,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-25',
            'id_armada' => $armada->id_armada,
            'id_supir'  => $supirB,
            'id_proyek' => $proyekB->id_proyek,
            'id_rute'   => $rute,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $res = $this->getJson('/api/penugasan/board?dari=2026-09-25&sampai=2026-09-25');
        $res->assertStatus(200);

        $assignments = collect($res->json('data.assignments'))->where('id_armada', $armada->id_armada);
        $this->assertCount(2, $assignments);
        $this->assertEqualsCanonicalizing(
            [$proyekA->id_proyek, $proyekB->id_proyek],
            $assignments->pluck('id_proyek')->all(),
        );
    }

    public function test_tarif_tidak_ketemu_penugasan_tetap_dibuat_dengan_peringatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir('Deni Tanpa Tarif');

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-05',
            'id_armada' => $armada->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $rute,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);
        $this->assertNotEmpty($res->json('data.peringatan'));
        $this->assertStringContainsString('Tarif uang jalan', $res->json('data.peringatan.0'));

        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
        $this->assertDatabaseHas('penugasan', [
            'id_armada' => $armada->id_armada, 'id_supir' => $supir,
            'tanggal_tugas' => '2026-09-05', 'estimasi_biaya' => null,
        ]);
    }

    public function test_hapus_penugasan_menyinkronkan_nominal_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir('Eka Sinkron');

        $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-03',
            'id_armada' => $armada->id_armada, 'id_supir' => $supir,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $rute,
        ])->assertJsonPath('data.sukses', 3);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supir)->value('id_pengajuan');
        $ids = DB::table('penugasan')->where('id_supir', $supir)->orderBy('tanggal_tugas')->pluck('id_penugasan')->all();
        $this->assertCount(3, $ids);

        $this->deleteJson('/api/penugasan/' . $ids[0])->assertStatus(200);

        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $idPengajuan, 'nominal' => 300000,
            'periode_dari' => '2026-09-02', 'periode_sampai' => '2026-09-03', 'status' => 'menunggu_approval',
        ]);

        $this->deleteJson('/api/penugasan/' . $ids[1])->assertStatus(200);
        $this->deleteJson('/api/penugasan/' . $ids[2])->assertStatus(200);

        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('dihapus_pada'));
    }

    public function test_assign_unit_vendor_unit_only(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 120000.0);
        $vendorUnit = $this->makeVendorUnitOnly();
        $supir = $this->makeSupir('Fajar Vendor');

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-07',
            'id_armada_vendor' => $vendorUnit['id_armada_vendor'],
            'id_supir'         => $supir,
            'id_proyek'        => $proyek->id_proyek,
            'id_rute'          => $rute,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('penugasan', [
            'id_armada_vendor'  => $vendorUnit['id_armada_vendor'],
            'id_kontrak_vendor' => $vendorUnit['id_kontrak_vendor'],
            'id_supir'          => $supir,
            'sumber'            => 'vendor',
            'tanggal_tugas'     => '2026-09-07',
        ]);
    }

    public function test_uang_jalan_override_manual(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 50000.0);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir('Gita Override');

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'    => '2026-09-08',
            'id_armada'  => $armada->id_armada,
            'id_supir'   => $supir,
            'id_proyek'  => $proyek->id_proyek,
            'id_rute'    => $rute,
            'uang_jalan' => 99000,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $this->assertDatabaseHas('penugasan', [
            'id_armada' => $armada->id_armada, 'id_supir' => $supir,
            'tanggal_tugas' => '2026-09-08', 'estimasi_biaya' => 99000,
        ]);
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_supir' => $supir, 'tarif_per_hari' => 99000, 'nominal' => 99000,
        ]);
    }

    public function test_id_armada_dan_id_armada_vendor_bersamaan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 100000.0);
        $armada     = $this->makeArmada();
        $vendorUnit = $this->makeVendorUnitOnly();
        $supir      = $this->makeSupir('Hadi Xor');

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal'          => '2026-09-09',
            'id_armada'        => $armada->id_armada,
            'id_armada_vendor' => $vendorUnit['id_armada_vendor'],
            'id_supir'         => $supir,
            'id_proyek'        => $proyek->id_proyek,
            'id_rute'          => $rute,
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, DB::table('penugasan')->where('id_supir', $supir)->count());
    }

    public function test_board_mengembalikan_unit_dan_assignment_dengan_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armada     = $this->makeArmada();
        $vendorUnit = $this->makeVendorUnitOnly();
        $supir      = $this->makeSupir('Indra Board', 'aktif', $armada->id_armada);

        $this->postJson('/api/penugasan/harian', [
            'tanggal'   => '2026-09-20',
            'id_armada' => $armada->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $rute,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $idPenugasan = (string) DB::table('penugasan')
            ->where('id_supir', $supir)->where('tanggal_tugas', '2026-09-20')
            ->value('id_penugasan');

        $this->makeTripUntukPenugasan($idPenugasan, 'selesai');
        $this->makeTripUntukPenugasan($idPenugasan, 'berjalan');
        $this->makeTripUntukPenugasan($idPenugasan, 'belum_mulai');

        $res = $this->getJson('/api/penugasan/board?dari=2026-09-20&sampai=2026-09-20');
        $res->assertStatus(200);

        $units = collect($res->json('data.units'));
        $this->assertGreaterThanOrEqual(2, $units->count());

        $unitArmada = $units->firstWhere('id_armada', $armada->id_armada);
        $this->assertNotNull($unitArmada);
        $this->assertSame('internal', $unitArmada['tipe']);
        $this->assertSame($supir, $unitArmada['id_supir_default']);
        $this->assertSame('Indra Board', $unitArmada['nama_supir_default']);

        $unitVendor = $units->firstWhere('id_armada_vendor', $vendorUnit['id_armada_vendor']);
        $this->assertNotNull($unitVendor);
        $this->assertSame('vendor', $unitVendor['tipe']);

        $assignment = collect($res->json('data.assignments'))->firstWhere('id_penugasan', $idPenugasan);
        $this->assertNotNull($assignment);
        $this->assertSame($proyek->id_proyek, $assignment['id_proyek']);
        $this->assertSame($rute, $assignment['id_rute']);
        $this->assertCount(3, $assignment['trips']);
        $statuses = collect($assignment['trips'])->pluck('status')->sort()->values()->all();
        $this->assertSame(['berjalan', 'berjalan', 'selesai'], $statuses);
    }

    public function test_mulai_trip_dari_penugasan_harian(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $rute   = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armada = $this->makeArmada();
        $supir  = $this->makeSupir('Joko Trip Harian');

        $this->postJson('/api/penugasan/harian', [
            'tanggal'   => now()->toDateString(),
            'id_armada' => $armada->id_armada,
            'id_supir'  => $supir,
            'id_proyek' => $proyek->id_proyek,
            'id_rute'   => $rute,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 1);

        $idPenugasan = (string) DB::table('penugasan')
            ->where('id_supir', $supir)->where('tanggal_tugas', now()->toDateString())
            ->value('id_penugasan');

        $this->postJson('/api/trip/mulai', ['id_penugasan' => $idPenugasan])
            ->assertStatus(201);

        $trip = DB::table('trip')
            ->join('jadwal_keberangkatan', 'jadwal_keberangkatan.id_jadwal', '=', 'trip.id_jadwal')
            ->where('jadwal_keberangkatan.id_penugasan', $idPenugasan)
            ->first();
        $this->assertNotNull($trip);
        $this->assertSame('berjalan', $trip->status);
        $this->assertNotNull($trip->waktu_checkin);

        $this->assertSame('digunakan', DB::table('armada')->where('id_armada', $armada->id_armada)->value('status'));
    }

    public function test_edit_supir_ke_supir_dengan_penugasan_tanggal_lain_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek  = $this->makeProyek();
        $rute    = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armadaA = $this->makeArmada();
        $armadaB = $this->makeArmada();
        $supirA  = $this->makeSupir('Supir A Edit');
        $supirB  = $this->makeSupir('Supir B Edit');

        $penugasanX = \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaA->id_armada,
            'id_supir'      => $supirA,
            'tanggal_tugas' => '2026-09-10',
            'status'        => 'aktif',
        ]);

        \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaB->id_armada,
            'id_supir'      => $supirB,
            'tanggal_tugas' => '2026-09-11',
            'status'        => 'aktif',
        ]);

        $res = $this->putJson('/api/penugasan/' . $penugasanX->id_penugasan, [
            'id_supir' => $supirB,
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('penugasan', [
            'id_penugasan'  => $penugasanX->id_penugasan,
            'id_supir'      => $supirB,
            'tanggal_tugas' => '2026-09-10',
        ]);
    }

    public function test_edit_supir_dobel_tanggal_sama_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek  = $this->makeProyek();
        $rute    = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armadaA = $this->makeArmada();
        $armadaB = $this->makeArmada();
        $supirA  = $this->makeSupir('Supir A Dobel');
        $supirB  = $this->makeSupir('Supir B Dobel');

        $penugasanA = \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaA->id_armada,
            'id_supir'      => $supirA,
            'tanggal_tugas' => '2026-09-10',
            'status'        => 'aktif',
        ]);

        \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaB->id_armada,
            'id_supir'      => $supirB,
            'tanggal_tugas' => '2026-09-10',
            'status'        => 'aktif',
        ]);

        $res = $this->putJson('/api/penugasan/' . $penugasanA->id_penugasan, [
            'id_supir' => $supirB,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $penugasanA->id_penugasan,
            'id_supir'     => $supirA,
        ]);
    }

    public function test_edit_tanggal_tugas_unit_boleh_dobel_tanggal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek  = $this->makeProyek();
        $rute    = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, null);
        $armadaU = $this->makeArmada();
        $supirA  = $this->makeSupir('Supir A Geser Tanggal');
        $supirC  = $this->makeSupir('Supir C Geser Tanggal');

        $penugasanX = \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaU->id_armada,
            'id_supir'      => $supirA,
            'tanggal_tugas' => '2026-09-10',
            'status'        => 'aktif',
        ]);

        \App\Modules\Penugasan\PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $rute,
            'id_armada'     => $armadaU->id_armada,
            'id_supir'      => $supirC,
            'tanggal_tugas' => '2026-09-15',
            'status'        => 'aktif',
        ]);

        $resDobel = $this->putJson('/api/penugasan/' . $penugasanX->id_penugasan, [
            'tanggal_tugas' => '2026-09-15',
        ]);
        $resDobel->assertStatus(200);
        $this->assertSame(2, DB::table('penugasan')
            ->where('id_armada', $armadaU->id_armada)
            ->where('tanggal_tugas', '2026-09-15')
            ->count());
    }

    public function test_edit_ganti_supir_baris_menunggu_approval_melepas_link_dan_sinkron_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek    = $this->makeProyek();
        $rute      = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $armada    = $this->makeArmada();
        $supirLama = $this->makeSupir('Lama Sinkron');
        $supirBaru = $this->makeSupir('Baru Sinkron');

        $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-03',
            'id_armada' => $armada->id_armada, 'id_supir' => $supirLama,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $rute,
        ])->assertJsonPath('data.sukses', 3);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supirLama)->value('id_pengajuan');
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $idPengajuan, 'nominal' => 450000, 'status' => 'menunggu_approval',
        ]);
        $ids = DB::table('penugasan')->where('id_supir', $supirLama)->orderBy('tanggal_tugas')->pluck('id_penugasan')->all();
        $this->assertCount(3, $ids);

        $res = $this->putJson('/api/penugasan/' . $ids[2], ['id_supir' => $supirBaru]);
        $res->assertStatus(200);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $ids[2], 'id_supir' => $supirBaru, 'id_pengajuan' => null,
        ]);
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan' => $idPengajuan, 'nominal' => 300000,
            'periode_dari' => '2026-09-01', 'periode_sampai' => '2026-09-02', 'status' => 'menunggu_approval',
        ]);
    }

    public function test_edit_ganti_supir_semua_baris_menghapus_pengajuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek    = $this->makeProyek();
        $rute      = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $armada    = $this->makeArmada();
        $supirLama = $this->makeSupir('Lama Semua');
        $supirBaru = $this->makeSupir('Baru Semua');

        $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-02',
            'id_armada' => $armada->id_armada, 'id_supir' => $supirLama,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $rute,
        ])->assertJsonPath('data.sukses', 2);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supirLama)->value('id_pengajuan');
        $ids = DB::table('penugasan')->where('id_supir', $supirLama)->orderBy('tanggal_tugas')->pluck('id_penugasan')->all();
        $this->assertCount(2, $ids);

        $this->putJson('/api/penugasan/' . $ids[0], ['id_supir' => $supirBaru])->assertStatus(200);
        $this->putJson('/api/penugasan/' . $ids[1], ['id_supir' => $supirBaru])->assertStatus(200);

        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('dihapus_pada'));
    }

    public function test_edit_ganti_supir_pengajuan_sudah_dicek_tidak_disentuh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek    = $this->makeProyek();
        $rute      = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $armada    = $this->makeArmada();
        $supirLama = $this->makeSupir('Lama Dicek');
        $supirBaru = $this->makeSupir('Baru Dicek');

        $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-03',
            'id_armada' => $armada->id_armada, 'id_supir' => $supirLama,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $rute,
        ])->assertJsonPath('data.sukses', 3);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supirLama)->value('id_pengajuan');
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => 'dicek']);
        $ids = DB::table('penugasan')->where('id_supir', $supirLama)->orderBy('tanggal_tugas')->pluck('id_penugasan')->all();

        $res = $this->putJson('/api/penugasan/' . $ids[0], ['id_supir' => $supirBaru]);
        $res->assertStatus(200);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $ids[0], 'id_supir' => $supirBaru, 'id_pengajuan' => $idPengajuan,
        ]);
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan'   => $idPengajuan,
            'nominal'        => 450000,
            'status'         => 'dicek',
            'periode_dari'   => '2026-09-01',
            'periode_sampai' => '2026-09-03',
        ]);
    }

    public function test_edit_ganti_supir_pengajuan_sudah_siap_transfer_tidak_disentuh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek    = $this->makeProyek();
        $rute      = $this->makeRute();
        $this->makeProyekRute($proyek->id_proyek, $rute, 150000.0);
        $armada    = $this->makeArmada();
        $supirLama = $this->makeSupir('Lama SiapTransfer');
        $supirBaru = $this->makeSupir('Baru SiapTransfer');

        $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-09-01', 'tanggal_sampai' => '2026-09-03',
            'id_armada' => $armada->id_armada, 'id_supir' => $supirLama,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $rute,
        ])->assertJsonPath('data.sukses', 3);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_supir', $supirLama)->value('id_pengajuan');
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->update(['status' => 'siap_transfer']);
        $ids = DB::table('penugasan')->where('id_supir', $supirLama)->orderBy('tanggal_tugas')->pluck('id_penugasan')->all();

        $res = $this->putJson('/api/penugasan/' . $ids[0], ['id_supir' => $supirBaru]);
        $res->assertStatus(200);

        $this->assertDatabaseHas('penugasan', [
            'id_penugasan' => $ids[0], 'id_supir' => $supirBaru, 'id_pengajuan' => $idPengajuan,
        ]);
        $this->assertDatabaseHas('pengajuan_pengeluaran', [
            'id_pengajuan'   => $idPengajuan,
            'nominal'        => 450000,
            'status'         => 'siap_transfer',
            'periode_dari'   => '2026-09-01',
            'periode_sampai' => '2026-09-03',
        ]);
    }
}
