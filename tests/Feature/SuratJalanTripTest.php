<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuratJalanTripTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa3333-0000-4000-8000-000000000001';

    private function actingAsSupir(): string
    {
        $this->ensurePerusahaan();
        $pengguna = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'SUPIR', 'username' => 'supir_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_pengguna' => $pengguna->id_pengguna, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir SJ', 'no_sim' => 'SIM-' . Str::random(8), 'jenis_sim' => 'B1', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        DB::table('menu')->insertOrIgnore([
            'id_menu' => self::ID_MENU_TRIP, 'nama_menu' => 'Trip Monitor', 'path' => '/trip', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        foreach (['lihat', 'tambah', 'ubah', 'hapus'] as $aksi) {
            DB::table('izin_peran')->insertOrIgnore([
                'id_izin' => (string) Str::uuid(), 'kode_peran' => 'SUPIR', 'id_menu' => self::ID_MENU_TRIP,
                'aksi' => $aksi, 'diizinkan' => 1, 'dibuat_pada' => now(),
            ]);
        }

        Sanctum::actingAs($pengguna, ['*']);

        return $idSupir;
    }

    private function makeTrip(string $idSupir): TripModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien SJ', 'dibuat_pada' => now(),
        ]);
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(8), 'nama_proyek' => 'Proyek SJ',
        ]);
        $penugasan = PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_proyek' => $proyek->id_proyek, 'id_supir' => $idSupir,
            'status' => 'aktif', 'tanggal_tugas' => now()->toDateString(),
        ]);
        $jadwal = JadwalKeberangkatanModel::create(['id_penugasan' => $penugasan->id_penugasan, 'waktu_berangkat' => now()]);

        return TripModel::create([
            'id_jadwal' => $jadwal->id_jadwal, 'status' => 'selesai',
            'waktu_checkin' => now()->subHours(2), 'waktu_checkout' => now(),
        ]);
    }

    private function tambahDrop(string $idTrip, int $urutan, string $lokasi): string
    {
        $id = (string) Str::uuid();
        DB::table('titik_drop_trip')->insert([
            'id_titik_drop' => $id, 'id_trip' => $idTrip, 'urutan' => $urutan, 'lokasi' => $lokasi, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function payloadDasar(array $tambahan): array
    {
        return array_merge([
            'biaya_bbm'       => 300000,
            'jarak_tempuh_km' => 85,
            'uang_jalan'      => 0,
            'foto'            => [UploadedFile::fake()->image('sj.jpg')],
        ], $tambahan);
    }

    public function test_supir_melihat_titik_drop_tripnya_dan_ditolak_untuk_trip_orang_lain(): void
    {
        $idSupir = $this->actingAsSupir();
        $trip = $this->makeTrip($idSupir);
        $idDrop1 = $this->tambahDrop($trip->id_trip, 1, 'Gudang Cikarang');
        $this->tambahDrop($trip->id_trip, 2, 'Gudang Bekasi');

        $this->getJson("/api/trip/{$trip->id_trip}/laporan-saya/titik-drop")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id_titik_drop', $idDrop1)
            ->assertJsonPath('data.0.lokasi', 'Gudang Cikarang');

        $this->actingAsSupir();
        $this->getJson("/api/trip/{$trip->id_trip}/laporan-saya/titik-drop")->assertStatus(403);
    }

    public function test_laporan_menyimpan_banyak_surat_jalan_per_titik_drop(): void
    {
        Storage::fake('public');
        $idSupir = $this->actingAsSupir();
        $trip = $this->makeTrip($idSupir);
        $idDrop1 = $this->tambahDrop($trip->id_trip, 1, 'Gudang Cikarang');
        $idDrop2 = $this->tambahDrop($trip->id_trip, 2, 'Gudang Bekasi');

        $res = $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", $this->payloadDasar([
            'surat_jalan' => [
                ['no_surat_jalan' => 'SJ-001', 'id_titik_drop' => $idDrop1],
                ['no_surat_jalan' => 'SJ-002', 'id_titik_drop' => $idDrop2],
                ['no_surat_jalan' => 'SJ-003'],
                ['no_surat_jalan' => '   '],
            ],
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.no_surat_jalan', 'SJ-001, SJ-002, SJ-003')
            ->assertJsonCount(3, 'data.surat_jalan')
            ->assertJsonPath('data.surat_jalan.0.lokasi_drop', 'Gudang Cikarang')
            ->assertJsonPath('data.surat_jalan.1.urutan_drop', 2)
            ->assertJsonPath('data.surat_jalan.2.id_titik_drop', null);

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'surat_jalan' => [['no_surat_jalan' => 'SJ-009', 'id_titik_drop' => $idDrop2]],
        ])->assertStatus(201)->assertJsonCount(1, 'data.surat_jalan');

        $this->assertSame(1, DB::table('surat_jalan_trip')->whereNull('dihapus_pada')->count());
    }

    public function test_titik_drop_milik_trip_lain_ditolak(): void
    {
        Storage::fake('public');
        $idSupir = $this->actingAsSupir();
        $trip = $this->makeTrip($idSupir);
        $tripLain = $this->makeTrip($idSupir);
        $idDropLain = $this->tambahDrop($tripLain->id_trip, 1, 'Gudang Lain');

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", $this->payloadDasar([
            'surat_jalan' => [['no_surat_jalan' => 'SJ-001', 'id_titik_drop' => $idDropLain]],
        ]))->assertStatus(422);
    }

    public function test_apk_lama_kirim_no_surat_jalan_tunggal_tetap_tercatat_dan_tidak_merusak_daftar(): void
    {
        Storage::fake('public');
        $idSupir = $this->actingAsSupir();
        $trip = $this->makeTrip($idSupir);
        $idDrop1 = $this->tambahDrop($trip->id_trip, 1, 'Gudang Cikarang');

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", $this->payloadDasar([
            'no_surat_jalan' => 'SJ-A, SJ-B',
        ]))->assertStatus(201)->assertJsonCount(2, 'data.surat_jalan');

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'surat_jalan' => [['no_surat_jalan' => 'SJ-A', 'id_titik_drop' => $idDrop1], ['no_surat_jalan' => 'SJ-B']],
        ])->assertStatus(201);

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'no_surat_jalan' => 'SJ-A, SJ-B',
            'uang_tol'       => 15000,
        ])->assertStatus(201)
            ->assertJsonCount(2, 'data.surat_jalan')
            ->assertJsonPath('data.surat_jalan.0.id_titik_drop', $idDrop1);

        $this->postJson("/api/trip/{$trip->id_trip}/laporan-saya", [
            'no_surat_jalan' => 'SJ-A, SJ-B, SJ-C',
        ])->assertStatus(201)
            ->assertJsonCount(3, 'data.surat_jalan')
            ->assertJsonPath('data.surat_jalan.0.id_titik_drop', $idDrop1)
            ->assertJsonPath('data.surat_jalan.2.id_titik_drop', null)
            ->assertJsonPath('data.no_surat_jalan', 'SJ-A, SJ-B, SJ-C');
    }

    public function test_ubah_titik_drop_trip_mempertahankan_id_supaya_tautan_surat_jalan_tidak_putus(): void
    {
        $idSupir = $this->actingAsSupir();
        $trip = $this->makeTrip($idSupir);
        $idDrop1 = $this->tambahDrop($trip->id_trip, 1, 'Gudang Cikarang');
        $idDrop2 = $this->tambahDrop($trip->id_trip, 2, 'Gudang Bekasi');

        app(\App\Modules\Trip\Contracts\TripRepositoryInterface::class)
            ->syncTitikDropTrip($trip->id_trip, ['gudang bekasi', 'Gudang Karawang']);

        $aktif = DB::table('titik_drop_trip')->where('id_trip', $trip->id_trip)->whereNull('dihapus_pada')->orderBy('urutan')->get();
        $this->assertCount(2, $aktif);
        $this->assertSame($idDrop2, $aktif[0]->id_titik_drop);
        $this->assertSame(1, (int) $aktif[0]->urutan);
        $this->assertSame('Gudang Karawang', $aktif[1]->lokasi);
        $this->assertNotNull(DB::table('titik_drop_trip')->where('id_titik_drop', $idDrop1)->value('dihapus_pada'));
    }
}
