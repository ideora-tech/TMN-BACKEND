<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FeeSupirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
    }

    private function proyek(string $tipeHarga = 'per_rit', ?string $idPerusahaan = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek ' . $tipeHarga,
            'tipe_harga'    => $tipeHarga,
        ]);
    }

    private function rute(string $nama = 'Cikarang - Priok'): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => $nama, 'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function daftarkanRute(string $idProyek, string $idRute): void
    {
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $idProyek, 'id_rute' => $idRute, 'uang_jalan' => 300000, 'dibuat_pada' => now(),
        ]);
    }

    private function armada(): string
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . random_int(1000, 9999) . ' ' . strtoupper(Str::random(3)),
            'merk'          => 'Hino',
        ])->id_armada;
    }

    private function supir(?string $idPengguna = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_pengguna' => $idPengguna,
            'nama' => 'Supir ' . Str::random(4), 'no_sim' => 'SIM-' . Str::random(8), 'status' => 'aktif',
            'dibuat_pada' => now(),
        ]);

        return $id;
    }

    private function tugaskan(array $data): string
    {
        $res = $this->postJson('/api/penugasan/harian', $data)->assertStatus(200)->assertJsonPath('data.sukses', 1);

        return (string) $res->json('data.penugasan.0.id_penugasan');
    }

    private function tripBerjalan(string $idPenugasan): string
    {
        $jadwal = JadwalKeberangkatanModel::create(['id_penugasan' => $idPenugasan, 'waktu_berangkat' => now()]);
        $trip   = TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'berjalan']);
        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $trip->id_trip, 'dibuat_pada' => now(),
        ]);

        return (string) $trip->id_trip;
    }

    private function checkout(string $idTrip): void
    {
        $this->postJson("/api/trip/{$idTrip}/checkout")->assertStatus(200);
    }

    private function feeSelesai(string $idProyek, string $idRute, string $idSupir, string $tanggal, float $fee): string
    {
        $idPenugasan = $this->tugaskan([
            'tanggal' => $tanggal, 'id_armada' => $this->armada(), 'id_supir' => $idSupir,
            'id_proyek' => $idProyek, 'id_rute' => $idRute, 'fee_supir' => $fee,
        ]);
        $idTrip = $this->tripBerjalan($idPenugasan);
        $this->checkout($idTrip);
        $this->travel(1)->minutes();

        return $idTrip;
    }

    private function loginSupir(): array
    {
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_peran' => 'SUPIR',
            'username' => 'supir_' . Str::random(8), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);

        $idMenu = DB::table('menu')->where('path', '/supir')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => 'Supir', 'path' => '/supir', 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        if (!DB::table('izin_peran')->where('kode_peran', 'SUPIR')->where('id_menu', $idMenu)->where('aksi', 'lihat')->exists()) {
            DB::table('izin_peran')->insert([
                'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'SUPIR',
                'id_menu' => $idMenu, 'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }

        return ['pengguna' => $pengguna, 'id_supir' => $this->supir((string) $pengguna->id_pengguna)];
    }

    public function test_fee_on_call_diisi_di_penugasan_bukan_di_tarif_rute_proyek(): void
    {
        $this->assertFalse(Schema::hasColumn('proyek_rute', 'fee_supir'));
        $this->assertFalse(Schema::hasColumn('proyek_rute', 'fee_supir_jenjang'));

        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-10-14', 'tanggal_sampai' => '2026-10-15', 'id_armada' => $this->armada(), 'id_supir' => $this->supir(),
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute, 'fee_supir' => 90000,
        ])->assertStatus(200)->assertJsonPath('data.sukses', 2);
        $denganFee = (string) $res->json('data.penugasan.0.id_penugasan');
        $this->assertSame([90000.0, 90000.0], DB::table('penugasan')->where('id_proyek', $proyek->id_proyek)->pluck('fee_supir')->map(fn ($f) => (float) $f)->all());

        $tanpaFee = $this->tugaskan([
            'tanggal' => '2026-10-16', 'id_armada' => $this->armada(), 'id_supir' => $this->supir(),
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute,
        ]);
        $this->assertNull(DB::table('penugasan')->where('id_penugasan', $tanpaFee)->value('fee_supir'));

        $this->getJson('/api/penugasan/board?dari=2026-10-14&sampai=2026-10-16')->assertStatus(200);
        $this->getJson("/api/penugasan/{$denganFee}")->assertStatus(200)->assertJsonPath('data.fee_supir', 90000);
        $this->getJson("/api/proyek/{$proyek->id_proyek}/rute")->assertStatus(200)
            ->assertJsonMissingPath('data.0.fee_supir')
            ->assertJsonMissingPath('data.0.fee_supir_jenjang');
    }

    public function test_penugasan_proyek_dedicate_tidak_mendapat_fee(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('borongan');
        $idRute = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);

        $idPenugasan = $this->tugaskan([
            'tanggal' => '2026-10-14', 'id_armada' => $this->armada(), 'id_supir' => $this->supir(),
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute, 'fee_supir' => 50000,
        ]);
        $this->assertNull(DB::table('penugasan')->where('id_penugasan', $idPenugasan)->value('fee_supir'));

        $this->checkout($this->tripBerjalan($idPenugasan));
        $this->assertSame(0, DB::table('fee_supir_trip')->count());
    }

    public function test_fee_tercatat_per_trip_saat_checkout_dan_mengikuti_perubahan_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek  = $this->proyek('per_rit');
        $idRute  = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);
        $idSupir = $this->supir();

        $idPenugasan = $this->tugaskan([
            'tanggal' => '2026-10-14', 'id_armada' => $this->armada(), 'id_supir' => $idSupir,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute, 'fee_supir' => 75000,
        ]);

        $tripSatu = $this->tripBerjalan($idPenugasan);
        $this->assertSame(0, DB::table('fee_supir_trip')->count());

        $this->checkout($tripSatu);
        $this->checkout($this->tripBerjalan($idPenugasan));

        $fee = DB::table('fee_supir_trip')->whereNull('dihapus_pada')->get();
        $this->assertCount(2, $fee);
        $this->assertSame([$idSupir], $fee->pluck('id_supir')->unique()->values()->all());
        $this->assertEquals(150000, $fee->sum('nominal'));
        $this->assertSame('2026-10-14', substr((string) $fee->first()->tanggal, 0, 10));

        $this->putJson("/api/penugasan/{$idPenugasan}", ['fee_supir' => 100000])->assertStatus(200);
        $this->assertEquals(200000, DB::table('fee_supir_trip')->whereNull('dihapus_pada')->sum('nominal'));

        $this->putJson("/api/penugasan/{$idPenugasan}", ['fee_supir' => 0])->assertStatus(200);
        $this->assertSame(0, DB::table('fee_supir_trip')->whereNull('dihapus_pada')->where('nominal', '>', 0)->count());
        $this->assertSame(2, DB::table('fee_supir_trip')->whereNull('dihapus_pada')->count());

        $this->putJson("/api/penugasan/{$idPenugasan}", ['fee_supir' => 80000])->assertStatus(200);
        $this->assertEquals(160000, DB::table('fee_supir_trip')->whereNull('dihapus_pada')->sum('nominal'));

        $this->deleteJson("/api/trip/{$tripSatu}")->assertStatus(200);
        $this->assertSame(1, DB::table('fee_supir_trip')->whereNull('dihapus_pada')->count());
    }

    public function test_fee_yang_diisi_belakangan_tetap_milik_supir_yang_menjalankan_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);
        $idSupirAsli = $this->supir();
        $idSupirBaru = $this->supir();

        $idPenugasan = $this->tugaskan([
            'tanggal' => '2026-10-14', 'id_armada' => $this->armada(), 'id_supir' => $idSupirAsli,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute,
        ]);
        $this->checkout($this->tripBerjalan($idPenugasan));

        $baris = DB::table('fee_supir_trip')->first();
        $this->assertNotNull($baris);
        $this->assertEquals(0, (float) $baris->nominal);
        $this->assertSame($idSupirAsli, $baris->id_supir);

        $this->putJson("/api/penugasan/{$idPenugasan}", ['id_supir' => $idSupirBaru])->assertStatus(200);
        $this->putJson("/api/penugasan/{$idPenugasan}", ['fee_supir' => 50000])->assertStatus(200);

        $baris = DB::table('fee_supir_trip')->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $baris);
        $this->assertSame($idSupirAsli, $baris[0]->id_supir);
        $this->assertEquals(50000, (float) $baris[0]->nominal);

        $proyekDedicate = $this->proyek('unit_driver');
        $this->putJson("/api/penugasan/{$idPenugasan}", ['id_proyek' => $proyekDedicate->id_proyek])->assertStatus(200);
        $this->assertSame(0, DB::table('fee_supir_trip')->whereNull('dihapus_pada')->count());

        $this->putJson("/api/penugasan/{$idPenugasan}", ['id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute])->assertStatus(200);
        $baris = DB::table('fee_supir_trip')->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $baris);
        $this->assertSame($idSupirAsli, $baris[0]->id_supir);
        $this->assertEquals(50000, (float) $baris[0]->nominal);
    }

    public function test_koreksi_penugasan_setelah_trip_selesai_ikut_memperbarui_rute_dan_tanggal_fee(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $ruteA = $this->rute('Rute A');
        $ruteB = $this->rute('Rute B');
        $this->daftarkanRute($proyek->id_proyek, $ruteA);
        $this->daftarkanRute($proyek->id_proyek, $ruteB);

        $idPenugasan = $this->tugaskan([
            'tanggal' => '2026-10-31', 'id_armada' => $this->armada(), 'id_supir' => $this->supir(),
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $ruteA, 'fee_supir' => 50000,
        ]);
        $this->checkout($this->tripBerjalan($idPenugasan));

        $this->putJson("/api/penugasan/{$idPenugasan}", ['id_rute' => $ruteB])->assertStatus(200);
        $this->putJson("/api/penugasan/{$idPenugasan}", ['tanggal_tugas' => '2026-11-01'])->assertStatus(200);

        $baris = DB::table('fee_supir_trip')->whereNull('dihapus_pada')->first();
        $this->assertSame($ruteB, $baris->id_rute);
        $this->assertSame('2026-11-01', substr((string) $baris->tanggal, 0, 10));
        $this->assertEquals(50000, (float) $baris->nominal);
    }

    public function test_trip_yang_selesai_tanpa_catatan_fee_tidak_dibuatkan_fee_dari_edit_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);

        $idPenugasan = $this->tugaskan([
            'tanggal' => '2026-10-14', 'id_armada' => $this->armada(), 'id_supir' => $this->supir(),
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute, 'fee_supir' => 70000,
        ]);
        $idTrip = $this->tripBerjalan($idPenugasan);
        DB::table('trip')->where('id_trip', $idTrip)->update(['status' => 'selesai', 'waktu_checkout' => now()]);

        $this->putJson("/api/penugasan/{$idPenugasan}", ['id_supir' => $this->supir(), 'fee_supir' => 80000])->assertStatus(200);
        $this->assertSame(0, DB::table('fee_supir_trip')->count());
    }

    public function test_supir_melihat_fee_miliknya_per_bulan_di_aplikasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute('Cikarang - Priok');
        $this->daftarkanRute($proyek->id_proyek, $idRute);

        $akun    = $this->loginSupir();
        $lainnya = $this->supir();

        foreach (['2026-10-03' => $akun['id_supir'], '2026-10-20' => $akun['id_supir'], '2026-09-28' => $akun['id_supir'], '2026-10-05' => $lainnya] as $tanggal => $idSupir) {
            $this->feeSelesai($proyek->id_proyek, $idRute, $idSupir, $tanggal, 75000);
        }

        Sanctum::actingAs($akun['pengguna'], ['*']);
        $res = $this->getJson('/api/supir/me/fee?bulan=2026-10')->assertStatus(200);

        $res->assertJsonPath('data.bulan', '2026-10')
            ->assertJsonPath('data.jumlah_trip', 2)
            ->assertJsonPath('data.total', 150000);
        $this->assertSame(['2026-10-20', '2026-10-03'], array_column($res->json('data.item'), 'tanggal'));
        $this->assertSame('Cikarang - Priok', $res->json('data.item.0.nama_rute'));
        $this->assertNotEmpty($res->json('data.item.0.nopol'));

        $this->getJson('/api/supir/me/fee?bulan=2026-09')->assertStatus(200)->assertJsonPath('data.jumlah_trip', 1);
        $this->getJson('/api/supir/me/fee?bulan=oktober')->assertStatus(422);
    }

    public function test_urutan_trip_supir_untuk_dialog_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);
        $idSupir = $this->supir();

        $dasar = "id_proyek={$proyek->id_proyek}&id_supir={$idSupir}&tanggal=2026-10-14";
        $this->getJson("/api/fee-supir/urutan-trip?{$dasar}")->assertStatus(200)
            ->assertJsonPath('data.on_call', true)
            ->assertJsonPath('data.trip_ke', 1);

        $this->feeSelesai($proyek->id_proyek, $idRute, $idSupir, '2026-10-14', 20000);
        $kedua = $this->tugaskan([
            'tanggal' => '2026-10-14', 'id_armada' => $this->armada(), 'id_supir' => $idSupir,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $idRute, 'fee_supir' => 15000,
        ]);

        $this->getJson("/api/fee-supir/urutan-trip?{$dasar}")->assertStatus(200)->assertJsonPath('data.trip_ke', 3);
        $this->getJson("/api/fee-supir/urutan-trip?{$dasar}&id_penugasan={$kedua}")->assertStatus(200)->assertJsonPath('data.trip_ke', 2);
        $this->getJson("/api/fee-supir/urutan-trip?id_proyek={$proyek->id_proyek}&id_supir={$idSupir}&tanggal=2026-10-15")
            ->assertStatus(200)->assertJsonPath('data.trip_ke', 1);
        $this->getJson("/api/fee-supir/urutan-trip?id_proyek={$proyek->id_proyek}&id_supir={$this->supir()}&tanggal=2026-10-14")
            ->assertStatus(200)->assertJsonPath('data.trip_ke', 1);

        $dedicate = $this->proyek('borongan');
        $this->getJson("/api/fee-supir/urutan-trip?id_proyek={$dedicate->id_proyek}&id_supir={$idSupir}&tanggal=2026-10-14")
            ->assertStatus(200)->assertJsonPath('data.on_call', false)->assertJsonPath('data.trip_ke', null);

        $asing = $this->proyek('per_rit', (string) Str::uuid());
        $this->getJson("/api/fee-supir/urutan-trip?id_proyek={$asing->id_proyek}&id_supir={$idSupir}&tanggal=2026-10-14")->assertStatus(404);
        $this->getJson("/api/fee-supir/urutan-trip?id_proyek={$proyek->id_proyek}")->assertStatus(422);
    }

    public function test_rekap_web_mengelompokkan_fee_per_supir_dan_rincian_per_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute('Cikarang - Priok');
        $this->daftarkanRute($proyek->id_proyek, $idRute);
        $idBudi = $this->supir();
        $idAndi = $this->supir();
        DB::table('supir')->where('id_supir', $idBudi)->update(['nama' => 'Budi Santoso']);
        DB::table('supir')->where('id_supir', $idAndi)->update(['nama' => 'Andi Wijaya']);

        $this->feeSelesai($proyek->id_proyek, $idRute, $idBudi, '2026-10-02', 75000);
        $this->feeSelesai($proyek->id_proyek, $idRute, $idBudi, '2026-10-05', 75000);
        $this->feeSelesai($proyek->id_proyek, $idRute, $idAndi, '2026-10-06', 50000);
        $this->feeSelesai($proyek->id_proyek, $idRute, $idAndi, '2026-10-07', 0);
        $this->feeSelesai($proyek->id_proyek, $idRute, $idAndi, '2026-09-30', 75000);
        $tripLain = $this->feeSelesai($proyek->id_proyek, $idRute, $idAndi, '2026-10-08', 75000);
        DB::table('fee_supir_trip')->where('id_trip', $tripLain)->update(['id_perusahaan' => (string) Str::uuid()]);

        $this->actingAsRole('KEUANGAN');
        $periode = 'dari=2026-10-01&sampai=2026-10-31';

        $this->getJson("/api/fee-supir/ringkasan?{$periode}")->assertStatus(200)
            ->assertJsonPath('data.jumlah_trip', 3)
            ->assertJsonPath('data.jumlah_supir', 2)
            ->assertJsonPath('data.total', 200000);

        $rekap = $this->getJson("/api/fee-supir/rekap?{$periode}")->assertStatus(200);
        $rekap->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id_supir', $idBudi)
            ->assertJsonPath('data.0.jumlah_trip', 2)
            ->assertJsonPath('data.0.total', 150000)
            ->assertJsonPath('data.0.tanggal_terakhir', '2026-10-05')
            ->assertJsonPath('data.1.id_supir', $idAndi)
            ->assertJsonPath('data.1.jumlah_trip', 1)
            ->assertJsonPath('data.1.total', 50000);

        $this->getJson("/api/fee-supir/rekap?{$periode}&search=andi")->assertStatus(200)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id_supir', $idAndi);

        $rincian = $this->getJson("/api/fee-supir?{$periode}&limit=2")->assertStatus(200);
        $rincian->assertJsonPath('meta.total', 3)->assertJsonPath('meta.totalPages', 2)
            ->assertJsonPath('data.0.tanggal', '2026-10-06')
            ->assertJsonPath('data.0.nama_supir', 'Andi Wijaya')
            ->assertJsonPath('data.0.nama_rute', 'Cikarang - Priok')
            ->assertJsonPath('data.0.nominal', 50000);
        $this->assertNotEmpty($rincian->json('data.0.nopol'));

        $this->getJson("/api/fee-supir?{$periode}&id_supir={$idBudi}")->assertStatus(200)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.tanggal', '2026-10-05');

        $this->getJson('/api/fee-supir/rekap?dari=2026-10-31&sampai=2026-10-01')->assertStatus(422);
        $this->getJson('/api/fee-supir/rekap?dari=2025-01-01&sampai=2026-10-31')->assertStatus(422);
        $this->getJson('/api/fee-supir?dari=14-10-2026')->assertStatus(422);
    }

    public function test_rekap_fee_supir_bisa_diekspor_ke_excel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->proyek('per_rit');
        $idRute = $this->rute();
        $this->daftarkanRute($proyek->id_proyek, $idRute);
        $this->feeSelesai($proyek->id_proyek, $idRute, $this->supir(), '2026-10-03', 60000);

        $this->actingAsRole('KEUANGAN');
        $res = $this->get('/api/fee-supir/export/excel?dari=2026-10-01&sampai=2026-10-31');

        $res->assertStatus(200);
        $this->assertStringContainsString('fee-supir-20261001-20261031.xlsx', (string) $res->headers->get('content-disposition'));

        $buku = IOFactory::load($res->baseResponse->getFile()->getPathname());
        $this->assertSame(['Rekap per Supir', 'Rincian Trip'], $buku->getSheetNames());
        $rekap = $buku->getSheetByName('Rekap per Supir');
        $this->assertSame('Supir', $rekap->getCell('B3')->getValue());
        $this->assertSame('TOTAL', $rekap->getCell('B5')->getValue());
        $this->assertEquals(60000, $rekap->getCell('E5')->getValue());
        $this->assertEquals(60000, $buku->getSheetByName('Rincian Trip')->getCell('H4')->getValue());
    }

    public function test_rekap_fee_supir_hanya_untuk_peran_yang_diberi_izin(): void
    {
        $this->actingAsRole('OPERASIONAL');
        $this->getJson('/api/fee-supir/rekap')->assertStatus(403);
        $this->getJson('/api/fee-supir')->assertStatus(403);
        $this->get('/api/fee-supir/export/excel')->assertStatus(403);

        foreach (['KEUANGAN', 'MANAGER', 'ADMIN'] as $peran) {
            $this->actingAsRole($peran);
            $this->getJson('/api/fee-supir/ringkasan')->assertStatus(200)->assertJsonPath('data.jumlah_trip', 0);
        }
    }
}
