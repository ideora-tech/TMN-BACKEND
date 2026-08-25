<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardArmadaTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $status = 'tersedia', ?string $nopol = null, ?string $idPerusahaan = null): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nopol'         => $nopol ?? ('B ' . strtoupper(Str::random(6))),
            'status'        => $status,
        ]);
    }

    private function makePerawatan(
        string $idArmada,
        string $status,
        string $tanggal = '2026-08-01',
        ?string $jadwal = null,
        string $jenis = 'Ganti Oli',
        bool $dihapus = false,
    ): string {
        $id = (string) Str::uuid();
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => $id, 'id_armada' => $idArmada, 'tanggal' => $tanggal,
            'jenis_perawatan' => $jenis, 'biaya' => 100000, 'status' => $status,
            'jadwal_servis_berikutnya' => $jadwal, 'dibuat_pada' => now(),
            'dihapus_pada' => $dihapus ? now() : null,
        ]);
        return $id;
    }

    public function test_butuh_autentikasi(): void
    {
        $this->getJson('/api/armada/dashboard')->assertStatus(401);
    }

    public function test_statistik_menghitung_armada_per_status(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeArmada('tersedia');
        $this->makeArmada('tersedia');
        $this->makeArmada('digunakan');
        $this->makeArmada('perawatan');
        $this->makeArmada('tidak_aktif');
        $terhapus = $this->makeArmada('tersedia');
        $terhapus->softDelete();

        $res = $this->getJson('/api/armada/dashboard');

        $res->assertStatus(200);
        $stat = $res->json('data.statistik');
        $this->assertSame(5, $stat['total']);
        $this->assertSame(2, $stat['tersedia']);
        $this->assertSame(1, $stat['digunakan']);
        $this->assertSame(1, $stat['perawatan']);
        $this->assertSame(1, $stat['tidak_aktif']);
    }

    public function test_perawatan_aktif_hanya_terjadwal_dan_dalam_proses_urut_proses_dulu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $a = $this->makeArmada('perawatan', 'B 1111 AA');
        $b = $this->makeArmada('tersedia', 'B 2222 BB');
        $c = $this->makeArmada('tersedia', 'B 3333 CC');
        $this->makePerawatan($a->id_armada, 'dalam_proses', '2026-08-05', null, 'Turun Mesin');
        $this->makePerawatan($b->id_armada, 'terjadwal', '2026-08-01', null, 'Ganti Oli');
        $this->makePerawatan($c->id_armada, 'selesai', '2026-07-01');
        $this->makePerawatan($c->id_armada, 'dalam_proses', '2026-07-15', null, 'Ganti Ban', true);

        $res = $this->getJson('/api/armada/dashboard');

        $res->assertStatus(200);
        $aktif = $res->json('data.perawatanAktif');
        $this->assertCount(2, $aktif);
        $this->assertSame('B 1111 AA', $aktif[0]['nopol']);
        $this->assertSame('dalam_proses', $aktif[0]['status']);
        $this->assertSame('Turun Mesin', $aktif[0]['jenis_perawatan']);
        $this->assertSame('B 2222 BB', $aktif[1]['nopol']);
        $this->assertSame('terjadwal', $aktif[1]['status']);

        $stat = $res->json('data.statistik');
        $this->assertSame(1, $stat['dalamPerawatan']);
        $this->assertSame(1, $stat['terjadwal']);
    }

    public function test_harus_servis_memuat_basis_hari_dan_km(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $hari = $this->makeArmada('tersedia', 'B 4444 DD');
        $this->makePerawatan($hari->id_armada, 'selesai', '2026-06-01', now()->addDays(10)->toDateString(), 'Servis Besar');

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
            'interval_hari' => 180, 'interval_km' => 10000, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $km = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 5555 KM',
            'status' => 'tersedia', 'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => (string) Str::uuid(), 'id_armada' => $km->id_armada,
            'id_jenis_perawatan' => $idJenisPerawatan, 'tanggal' => '2026-05-01',
            'jenis_perawatan' => 'Servis', 'biaya' => 100000, 'status' => 'selesai',
            'km_odometer' => 50000, 'dibuat_pada' => now(),
        ]);
        DB::table('perawatan_armada')->insert([
            'id_perawatan' => (string) Str::uuid(), 'id_armada' => $km->id_armada,
            'id_jenis_perawatan' => null, 'tanggal' => '2026-08-01',
            'jenis_perawatan' => 'Servis', 'biaya' => 100000, 'status' => 'selesai',
            'km_odometer' => 59500, 'dibuat_pada' => now(),
        ]);

        $res = $this->getJson('/api/armada/dashboard');

        $res->assertStatus(200);
        $harusServis = collect($res->json('data.harusServis'));
        $this->assertNotNull($harusServis->firstWhere('basis', 'hari'));
        $this->assertNotNull($harusServis->firstWhere('basis', 'km'));
        $this->assertSame(2, $res->json('data.statistik.harusServis'));
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $armadaLain = $this->makeArmada('perawatan', 'D 9999 ZZ', $lain);
        $this->makePerawatan($armadaLain->id_armada, 'dalam_proses');

        $res = $this->getJson('/api/armada/dashboard');

        $res->assertStatus(200);
        $this->assertSame(0, $res->json('data.statistik.total'));
        $this->assertCount(0, $res->json('data.perawatanAktif'));
    }

    public function test_menu_dashboard_armada_terdaftar(): void
    {
        $menu = DB::table('menu')->where('path', '/dashboard-armada')->first();

        $this->assertNotNull($menu);
        $this->assertSame('Dashboard Armada', $menu->nama_menu);
        $this->assertSame(0, (int) $menu->urutan);
        $this->assertSame('m0000001-0000-4000-8000-000000000020', $menu->id_menu_induk);

        $roles = DB::table('menu_peran')->where('id_menu', $menu->id_menu)
            ->pluck('kode_peran')->sort()->values()->all();
        $this->assertSame(['ADMIN', 'DISPATCHER', 'MANAGER', 'SUPERADMIN'], $roles);
    }
}
