<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArmadaServisJatuhTempoTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol = 'B 1234 XY'): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'status'        => 'tersedia',
        ]);
    }

    private function makePerawatan(string $idArmada, string $tanggal, ?string $jadwal, ?string $idIntervalPerawatan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'id_interval_perawatan' => $idIntervalPerawatan, 'biaya' => 100000, 'status' => 'selesai',
            'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_endpoint_mengembalikan_armada_dengan_servis_jatuh_tempo(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $idKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idKendaraan, 'interval_bulan' => 6, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(10)->toDateString(), $idInterval);

        $res = $this->getJson('/api/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armada->id_armada, $data[0]['id_armada']);
        $this->assertSame('Tiap 6 bulan', $data[0]['jenis_perawatan']);
    }

    public function test_catatan_insidental_tanpa_paket_menampilkan_label_perbaikan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(10)->toDateString());

        $res = $this->getJson('/api/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Perbaikan', $data[0]['jenis_perawatan']);
    }

    public function test_hanya_servis_terbaru_per_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-01-01', now()->addDays(5)->toDateString());
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(90)->toDateString());

        $res = $this->getJson('/api/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_param_days_custom(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(45)->toDateString());

        $resDefault = $this->getJson('/api/armada/servis-jatuh-tempo');
        $resDefault->assertStatus(200);
        $this->assertCount(0, $resDefault->json('data'));

        $resCustom = $this->getJson('/api/armada/servis-jatuh-tempo?days=60');
        $resCustom->assertStatus(200);
        $this->assertCount(1, $resCustom->json('data'));
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $armadaLain = ArmadaModel::create(['id_perusahaan' => $lain, 'nopol' => 'D 9999 ZZ', 'status' => 'tersedia']);
        $this->makePerawatan($armadaLain->id_armada, '2026-06-01', now()->addDays(10)->toDateString());

        $res = $this->getJson('/api/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_route_terdaftar_sebelum_apiresource_show(): void
    {
        $this->actingAsRole('SUPERADMIN');
        // Memastikan 'servis-jatuh-tempo' tidak tertangkap sebagai {id} pada GET armada/{id}
        $res = $this->getJson('/api/armada/servis-jatuh-tempo');
        $res->assertStatus(200); // bukan 404 "Armada tidak ditemukan"
    }

    /** @return array{idArmada: string, idInterval: string} */
    private function setupIntervalKm(int $intervalKm = 10000): array
    {
        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 5555 KM',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);

        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_bulan' => 6, 'interval_km' => $intervalKm, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        return ['idArmada' => $armada->id_armada, 'idInterval' => $idInterval];
    }

    private function makePerawatanKm(string $idArmada, string $tanggal, int $km, ?string $idInterval = null): void
    {
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => (string) Str::uuid(), 'id_armada' => $idArmada,
            'id_interval_perawatan' => $idInterval, 'tanggal' => $tanggal,
            'biaya' => 100000, 'status' => 'selesai',
            'km_odometer' => $km, 'jadwal_servis_berikutnya' => null, 'dibuat_pada' => now(),
        ]);
    }

    public function test_warning_km_muncul_saat_odometer_mendekati_interval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idInterval' => $idInterval] = $this->setupIntervalKm(10000);

        // servis paket tsb di km 50.000; odometer terakhir 59.500 → sisa 500 ≤ ambang 1.000
        $this->makePerawatanKm($idArmada, '2026-05-01', 50000, $idInterval);
        $this->makePerawatanKm($idArmada, '2026-08-01', 59500);

        $res = $this->getJson('/api/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $km = collect($res->json('data'))->firstWhere('basis', 'km');
        $this->assertNotNull($km);
        $this->assertSame($idArmada, $km['id_armada']);
        $this->assertSame('Tiap 10.000 km / 6 bulan', $km['jenis_perawatan']);
        $this->assertSame(60000, $km['km_jatuh_tempo']);
        $this->assertSame(500, $km['sisa_km']);
    }

    public function test_warning_km_tidak_muncul_saat_masih_jauh_dan_muncul_saat_lewat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idInterval' => $idInterval] = $this->setupIntervalKm(10000);
        $this->makePerawatanKm($idArmada, '2026-05-01', 50000, $idInterval);

        // odometer 57.000 → sisa 3.000 > ambang 1.000 → tidak ada warning km
        $this->makePerawatanKm($idArmada, '2026-07-01', 57000);
        $resJauh = $this->getJson('/api/armada/servis-jatuh-tempo');
        $this->assertNull(collect($resJauh->json('data'))->firstWhere('basis', 'km'));

        // odometer 61.000 → sisa -1.000 → lewat, warning muncul
        $this->makePerawatanKm($idArmada, '2026-08-01', 61000);
        $resLewat = $this->getJson('/api/armada/servis-jatuh-tempo');
        $km = collect($resLewat->json('data'))->firstWhere('basis', 'km');
        $this->assertNotNull($km);
        $this->assertSame(-1000, $km['sisa_km']);
    }

    public function test_prediksi_perawatan_memuat_dimensi_km_dan_status_gabungan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idInterval' => $idInterval] = $this->setupIntervalKm(10000);

        // Basis bulan masih aman (servis 2026-08-01, interval 6 bulan), tapi
        // basis km sudah lewat (50.000 + 10.000 < odometer 61.000) → status gabungan lewat.
        $this->makePerawatanKm($idArmada, '2026-08-01', 50000, $idInterval);
        $this->makePerawatanKm($idArmada, '2026-08-05', 61000);

        $res = $this->getJson("/api/armada/{$idArmada}/prediksi-perawatan");

        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id_interval_perawatan', $idInterval);
        $this->assertNotNull($item);
        $this->assertSame('Tiap 10.000 km / 6 bulan', $item['label']);
        $this->assertSame(10000, $item['interval_km']);
        $this->assertSame(50000, $item['km_servis_terakhir']);
        $this->assertSame(61000, $item['km_sekarang']);
        $this->assertSame(60000, $item['km_jatuh_tempo']);
        $this->assertSame(-1000, $item['sisa_km']);
        $this->assertSame('lewat_jatuh_tempo', $item['status_km']);
        $this->assertSame('lewat_jatuh_tempo', $item['status']);
    }

    public function test_prediksi_catatan_insidental_tidak_menggeser_jadwal_paket(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idInterval' => $idInterval] = $this->setupIntervalKm(10000);

        // Servis resmi tertaut paket di 2026-05-01 (jadwal otomatis +6 bulan = 2026-11-01).
        $this->makePerawatanKm($idArmada, '2026-05-01', 50000, $idInterval);
        // Servis insidental TANPA tautan paket, lebih baru — tidak boleh dianggap sebagai
        // riwayat paket ini walau tanggalnya lebih akhir.
        $this->makePerawatanKm($idArmada, '2026-09-01', 55000);

        $item = collect($this->getJson("/api/armada/{$idArmada}/prediksi-perawatan")
            ->assertStatus(200)->json('data'))->firstWhere('id_interval_perawatan', $idInterval);

        $this->assertNotNull($item);
        $this->assertSame(50000, $item['km_servis_terakhir']);
        $this->assertSame('2026-05-01', $item['tanggal_servis_terakhir']);
    }

    public function test_prediksi_pakai_tanggal_beli_sebagai_anchor_saat_belum_pernah_servis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_bulan' => 3, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 6001 TB',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
            'tanggal_beli' => now()->subDays(10)->toDateString(),
        ]);

        $res = $this->getJson("/api/armada/{$armada->id_armada}/prediksi-perawatan");
        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id_interval_perawatan', $idInterval);

        $this->assertNotNull($item);
        $expected = now()->subDays(10)->addMonths(3)->toDateString();
        $this->assertSame($expected, $item['jadwal_servis_berikutnya']);
        $this->assertSame('aman', $item['status']);
    }

    public function test_prediksi_unit_lama_tanpa_riwayat_tidak_dilaporkan_lewat_ribuan_hari(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_bulan' => 3, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 6003 TB',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
            'tanggal_beli' => now()->subYears(6)->toDateString(),
        ]);

        $item = collect($this->getJson("/api/armada/{$armada->id_armada}/prediksi-perawatan")
            ->assertStatus(200)->json('data'))->firstWhere('id_interval_perawatan', $idInterval);

        $this->assertNotNull($item);
        $this->assertNull($item['jadwal_servis_berikutnya']);
        $this->assertSame('belum_pernah', $item['status']);
    }

    public function test_prediksi_unit_terlewat_dalam_satu_interval_tetap_pakai_anchor_tanggal_beli(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_bulan' => 3, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 6004 TB',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
            'tanggal_beli' => now()->subMonths(4)->toDateString(),
        ]);

        $item = collect($this->getJson("/api/armada/{$armada->id_armada}/prediksi-perawatan")
            ->assertStatus(200)->json('data'))->firstWhere('id_interval_perawatan', $idInterval);

        $this->assertNotNull($item);
        $this->assertSame(now()->subMonths(4)->addMonths(3)->toDateString(), $item['jadwal_servis_berikutnya']);
        $this->assertSame('lewat_jatuh_tempo', $item['status']);
    }

    public function test_prediksi_tanpa_tanggal_beli_dan_tanpa_riwayat_tetap_belum_pernah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);
        $idInterval = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $idInterval, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_bulan' => 3, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 6002 TB',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);

        $res = $this->getJson("/api/armada/{$armada->id_armada}/prediksi-perawatan");
        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id_interval_perawatan', $idInterval);

        $this->assertNotNull($item);
        $this->assertNull($item['jadwal_servis_berikutnya']);
        $this->assertSame('belum_pernah', $item['status']);
    }
}
