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

    private function makePerawatan(string $idArmada, string $tanggal, ?string $jadwal, string $jenis = 'Ganti Oli'): string
    {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'jenis_perawatan' => $jenis, 'biaya' => 100000, 'status' => 'selesai',
            'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_endpoint_mengembalikan_armada_dengan_servis_jatuh_tempo(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(10)->toDateString(), 'Servis Besar');

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($armada->id_armada, $data[0]['id_armada']);
        $this->assertSame('Servis Besar', $data[0]['jenis_perawatan']);
    }

    public function test_hanya_servis_terbaru_per_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-01-01', now()->addDays(5)->toDateString());
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(90)->toDateString());

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_param_days_custom(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada();
        $this->makePerawatan($armada->id_armada, '2026-06-01', now()->addDays(45)->toDateString());

        $resDefault = $this->getJson('/api/v1/armada/servis-jatuh-tempo');
        $resDefault->assertStatus(200);
        $this->assertCount(0, $resDefault->json('data'));

        $resCustom = $this->getJson('/api/v1/armada/servis-jatuh-tempo?days=60');
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

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data'));
    }

    public function test_route_terdaftar_sebelum_apiresource_show(): void
    {
        $this->actingAsRole('SUPERADMIN');
        // Memastikan 'servis-jatuh-tempo' tidak tertangkap sebagai {id} pada GET armada/{id}
        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');
        $res->assertStatus(200); // bukan 404 "Armada tidak ditemukan"
    }

    /** @return array{idArmada: string, idJenisPerawatan: string} */
    private function setupIntervalKm(int $intervalKm = 10000): array
    {
        $idJenisKendaraan = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisKendaraan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jenis' => 'TRK-' . Str::random(5), 'nama_jenis' => 'Tronton', 'dibuat_pada' => now(),
        ]);

        $idJenisPerawatan = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $idJenisPerawatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Ganti Oli Mesin', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_jenis_perawatan' => $idJenisPerawatan, 'id_jenis_kendaraan' => $idJenisKendaraan,
            'interval_hari' => 180, 'interval_km' => $intervalKm, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);

        $armada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 5555 KM',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);

        return ['idArmada' => $armada->id_armada, 'idJenisPerawatan' => $idJenisPerawatan];
    }

    private function makePerawatanKm(string $idArmada, string $tanggal, int $km, ?string $idJenisPerawatan = null): void
    {
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => (string) Str::uuid(), 'id_armada' => $idArmada,
            'id_jenis_perawatan' => $idJenisPerawatan, 'tanggal' => $tanggal,
            'jenis_perawatan' => 'Servis', 'biaya' => 100000, 'status' => 'selesai',
            'km_odometer' => $km, 'jadwal_servis_berikutnya' => null, 'dibuat_pada' => now(),
        ]);
    }

    public function test_warning_km_muncul_saat_odometer_mendekati_interval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idJenisPerawatan' => $idJenis] = $this->setupIntervalKm(10000);

        // servis jenis tsb di km 50.000; odometer terakhir 59.500 → sisa 500 ≤ ambang 1.000
        $this->makePerawatanKm($idArmada, '2026-05-01', 50000, $idJenis);
        $this->makePerawatanKm($idArmada, '2026-08-01', 59500);

        $res = $this->getJson('/api/v1/armada/servis-jatuh-tempo');

        $res->assertStatus(200);
        $km = collect($res->json('data'))->firstWhere('basis', 'km');
        $this->assertNotNull($km);
        $this->assertSame($idArmada, $km['id_armada']);
        $this->assertSame('Ganti Oli Mesin', $km['jenis_perawatan']);
        $this->assertSame(60000, $km['km_jatuh_tempo']);
        $this->assertSame(500, $km['sisa_km']);
    }

    public function test_warning_km_tidak_muncul_saat_masih_jauh_dan_muncul_saat_lewat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idJenisPerawatan' => $idJenis] = $this->setupIntervalKm(10000);
        $this->makePerawatanKm($idArmada, '2026-05-01', 50000, $idJenis);

        // odometer 57.000 → sisa 3.000 > ambang 1.000 → tidak ada warning km
        $this->makePerawatanKm($idArmada, '2026-07-01', 57000);
        $resJauh = $this->getJson('/api/v1/armada/servis-jatuh-tempo');
        $this->assertNull(collect($resJauh->json('data'))->firstWhere('basis', 'km'));

        // odometer 61.000 → sisa -1.000 → lewat, warning muncul
        $this->makePerawatanKm($idArmada, '2026-08-01', 61000);
        $resLewat = $this->getJson('/api/v1/armada/servis-jatuh-tempo');
        $km = collect($resLewat->json('data'))->firstWhere('basis', 'km');
        $this->assertNotNull($km);
        $this->assertSame(-1000, $km['sisa_km']);
    }

    public function test_prediksi_perawatan_memuat_dimensi_km_dan_status_gabungan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        ['idArmada' => $idArmada, 'idJenisPerawatan' => $idJenis] = $this->setupIntervalKm(10000);

        // Basis hari masih aman (servis 2026-08-01, interval 180 hari), tapi
        // basis km sudah lewat (50.000 + 10.000 < odometer 61.000) → status gabungan lewat.
        $this->makePerawatanKm($idArmada, '2026-08-01', 50000, $idJenis);
        $this->makePerawatanKm($idArmada, '2026-08-05', 61000);

        $res = $this->getJson("/api/v1/armada/{$idArmada}/prediksi-perawatan");

        $res->assertStatus(200);
        $item = collect($res->json('data'))->firstWhere('id_jenis_perawatan', $idJenis);
        $this->assertNotNull($item);
        $this->assertSame(10000, $item['interval_km']);
        $this->assertSame(50000, $item['km_servis_terakhir']);
        $this->assertSame(61000, $item['km_sekarang']);
        $this->assertSame(60000, $item['km_jatuh_tempo']);
        $this->assertSame(-1000, $item['sisa_km']);
        $this->assertSame('lewat_jatuh_tempo', $item['status_km']);
        $this->assertSame('lewat_jatuh_tempo', $item['status']);
    }
}
