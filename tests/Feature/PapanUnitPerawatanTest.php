<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PapanUnitPerawatanTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol, string $status = 'tersedia', ?string $idPerusahaan = null, ?string $idJenisKendaraan = null): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan'      => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nopol'              => $nopol,
            'status'             => $status,
            'id_jenis_kendaraan' => $idJenisKendaraan,
        ]);
    }

    private function makeJenisKendaraan(string $nama = 'CDD', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_jenis'         => 'JK-' . Str::random(6),
            'nama_jenis'         => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeInterval(string $idJenisKendaraan, ?int $intervalBulan, ?int $intervalKm, ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('interval_perawatan')->insert([
            'id_interval_perawatan' => $id,
            'id_perusahaan'         => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_jenis_kendaraan'    => $idJenisKendaraan,
            'interval_bulan'        => $intervalBulan,
            'interval_km'           => $intervalKm,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);
        return $id;
    }

    private function makeServis(
        string $idArmada,
        string $tanggal,
        ?string $idIntervalPerawatan = null,
        ?string $jadwal = null,
        ?int $km = null,
        string $status = 'selesai',
    ): void {
        DB::table('perawatan_armada')->insert([
            'id_perawatan'             => (string) Str::uuid(),
            'id_armada'                => $idArmada,
            'id_interval_perawatan'    => $idIntervalPerawatan,
            'tanggal'                  => $tanggal,
            'biaya'                    => 100000,
            'status'                   => $status,
            'jadwal_servis_berikutnya' => $jadwal,
            'km_odometer'              => $km,
            'dibuat_pada'              => now(),
        ]);
    }

    public function test_unit_tanpa_interval_jumlah_nol_dan_jatuh_tempo_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 1000 AA');

        $res = $this->getJson('/api/perawatan-armada/papan-unit');

        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);
        $this->assertNotNull($row);
        $this->assertSame('B 1000 AA', $row['nopol']);
        $this->assertSame('tersedia', $row['status_armada']);
        $this->assertNull($row['nama_jenis_kendaraan']);
        $this->assertNull($row['servis_terakhir']);
        $this->assertSame(0, $row['jumlah_interval']);
        $this->assertSame([], $row['jatuh_tempo']);
    }

    public function test_basis_hari_segera_dan_lewat(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $kendaraanSegera = $this->makeJenisKendaraan('CDD');
        $armadaSegera = $this->makeArmada('B 1001 AA', 'tersedia', null, $kendaraanSegera);
        $paketSegera = $this->makeInterval($kendaraanSegera, 6, null);
        $this->makeServis($armadaSegera->id_armada, now()->subDays(174)->toDateString(), $paketSegera, now()->addDays(6)->toDateString());

        $kendaraanLewat = $this->makeJenisKendaraan('Tronton');
        $armadaLewat = $this->makeArmada('B 1002 AA', 'tersedia', null, $kendaraanLewat);
        $paketLewat = $this->makeInterval($kendaraanLewat, 3, null);
        $this->makeServis($armadaLewat->id_armada, now()->subDays(93)->toDateString(), $paketLewat, now()->subDays(3)->toDateString());

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $rowSegera = $data->firstWhere('id_armada', $armadaSegera->id_armada);
        $this->assertCount(1, $rowSegera['jatuh_tempo']);
        $this->assertSame('hari', $rowSegera['jatuh_tempo'][0]['basis']);
        $this->assertSame('segera', $rowSegera['jatuh_tempo'][0]['status']);
        $this->assertSame('6 hari lagi', $rowSegera['jatuh_tempo'][0]['keterangan']);
        $this->assertSame('Tiap 6 bulan', $rowSegera['jatuh_tempo'][0]['label']);
        $this->assertSame($paketSegera, $rowSegera['jatuh_tempo'][0]['id_interval_perawatan']);

        $rowLewat = $data->firstWhere('id_armada', $armadaLewat->id_armada);
        $this->assertCount(1, $rowLewat['jatuh_tempo']);
        $this->assertSame('hari', $rowLewat['jatuh_tempo'][0]['basis']);
        $this->assertSame('lewat_jatuh_tempo', $rowLewat['jatuh_tempo'][0]['status']);
        $this->assertSame('lewat 3 hari', $rowLewat['jatuh_tempo'][0]['keterangan']);
        $this->assertSame($paketLewat, $rowLewat['jatuh_tempo'][0]['id_interval_perawatan']);
    }

    public function test_basis_km_segera_dan_lewat(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $kendaraanSegera = $this->makeJenisKendaraan('CDD');
        $armadaSegera = $this->makeArmada('B 1003 AA', 'tersedia', null, $kendaraanSegera);
        $paketSegera = $this->makeInterval($kendaraanSegera, null, 10000);
        $this->makeServis($armadaSegera->id_armada, '2026-05-01', $paketSegera, null, 50000);
        $this->makeServis($armadaSegera->id_armada, '2026-08-01', null, null, 59200);

        $kendaraanLewat = $this->makeJenisKendaraan('Tronton');
        $armadaLewat = $this->makeArmada('B 1004 AA', 'tersedia', null, $kendaraanLewat);
        $paketLewat = $this->makeInterval($kendaraanLewat, null, 10000);
        $this->makeServis($armadaLewat->id_armada, '2026-05-01', $paketLewat, null, 50000);
        $this->makeServis($armadaLewat->id_armada, '2026-08-01', null, null, 61200);

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $rowSegera = $data->firstWhere('id_armada', $armadaSegera->id_armada);
        $this->assertCount(1, $rowSegera['jatuh_tempo']);
        $this->assertSame('km', $rowSegera['jatuh_tempo'][0]['basis']);
        $this->assertSame('segera', $rowSegera['jatuh_tempo'][0]['status']);
        $this->assertSame('sisa 800 km', $rowSegera['jatuh_tempo'][0]['keterangan']);
        $this->assertSame($paketSegera, $rowSegera['jatuh_tempo'][0]['id_interval_perawatan']);

        $rowLewat = $data->firstWhere('id_armada', $armadaLewat->id_armada);
        $this->assertCount(1, $rowLewat['jatuh_tempo']);
        $this->assertSame('km', $rowLewat['jatuh_tempo'][0]['basis']);
        $this->assertSame('lewat_jatuh_tempo', $rowLewat['jatuh_tempo'][0]['status']);
        $this->assertSame('lewat 1.200 km', $rowLewat['jatuh_tempo'][0]['keterangan']);
        $this->assertSame($paketLewat, $rowLewat['jatuh_tempo'][0]['id_interval_perawatan']);
    }

    public function test_unit_aman_jatuh_tempo_kosong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kendaraan = $this->makeJenisKendaraan('CDD');
        $armada = $this->makeArmada('B 1005 AA', 'tersedia', null, $kendaraan);
        $paket = $this->makeInterval($kendaraan, 6, 10000);
        $this->makeServis($armada->id_armada, '2026-08-01', $paket, now()->addDays(150)->toDateString(), 50000);
        $this->makeServis($armada->id_armada, '2026-08-05', null, null, 51000);

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertSame(1, $row['jumlah_interval']);
        $this->assertSame([], $row['jatuh_tempo']);
    }

    public function test_filter_hanya_jatuh_tempo(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $kendaraanDue = $this->makeJenisKendaraan('CDD');
        $armadaDue = $this->makeArmada('B 1006 AA', 'tersedia', null, $kendaraanDue);
        $paketDue = $this->makeInterval($kendaraanDue, 3, null);
        $this->makeServis($armadaDue->id_armada, now()->subDays(95)->toDateString(), $paketDue, now()->subDays(5)->toDateString());

        $armadaAman = $this->makeArmada('B 1007 AA');

        $res = $this->getJson('/api/perawatan-armada/papan-unit?hanya_jatuh_tempo=1');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertTrue($data->contains('id_armada', $armadaDue->id_armada));
        $this->assertFalse($data->contains('id_armada', $armadaAman->id_armada));
    }

    public function test_search_nopol(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armadaA = $this->makeArmada('B 2001 XX');
        $armadaB = $this->makeArmada('D 3002 YY');

        $res = $this->getJson('/api/perawatan-armada/papan-unit?search=2001');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertTrue($data->contains('id_armada', $armadaA->id_armada));
        $this->assertFalse($data->contains('id_armada', $armadaB->id_armada));
    }

    public function test_tenant_lain_tidak_bocor(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $lain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $lain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $armadaLain = $this->makeArmada('D 9999 ZZ', 'tersedia', $lain);

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertFalse($data->contains('id_armada', $armadaLain->id_armada));
    }

    public function test_armada_tidak_aktif_tidak_muncul(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 1008 AA', 'tidak_aktif');

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $this->assertFalse($data->contains('id_armada', $armada->id_armada));
    }

    public function test_servis_terakhir_insidental_tanpa_paket_menampilkan_label_perbaikan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 1009 AA');
        $this->makeServis($armada->id_armada, '2026-01-01', null, null, null, 'selesai');
        $this->makeServis($armada->id_armada, '2026-06-01', null, null, null, 'selesai');

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertNotNull($row['servis_terakhir']);
        $this->assertSame('2026-06-01', $row['servis_terakhir']['tanggal']);
        $this->assertSame('Perbaikan', $row['servis_terakhir']['label']);
    }

    public function test_servis_terakhir_bertaut_paket_menampilkan_label_paket(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kendaraan = $this->makeJenisKendaraan('CDD');
        $armada = $this->makeArmada('B 1010 AA', 'tersedia', null, $kendaraan);
        $paket = $this->makeInterval($kendaraan, 6, 10000);
        $this->makeServis($armada->id_armada, '2026-01-01', null, null, null, 'selesai');
        $this->makeServis($armada->id_armada, '2026-06-01', $paket, null, null, 'selesai');

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertNotNull($row['servis_terakhir']);
        $this->assertSame('2026-06-01', $row['servis_terakhir']['tanggal']);
        $this->assertSame('Tiap 10.000 km / 6 bulan', $row['servis_terakhir']['label']);
    }

    public function test_dua_armada_jenis_kendaraan_sama_berbagi_interval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kendaraan = $this->makeJenisKendaraan('CDD');
        $armadaA = $this->makeArmada('B 3001 AA', 'tersedia', null, $kendaraan);
        $armadaB = $this->makeArmada('B 3002 BB', 'tersedia', null, $kendaraan);
        $paket = $this->makeInterval($kendaraan, 3, null);
        $this->makeServis($armadaA->id_armada, now()->subDays(95)->toDateString(), $paket, now()->subDays(5)->toDateString());
        $this->makeServis($armadaB->id_armada, now()->subDays(95)->toDateString(), $paket, now()->subDays(5)->toDateString());

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $data = collect($res->json('data'));

        $rowA = $data->firstWhere('id_armada', $armadaA->id_armada);
        $rowB = $data->firstWhere('id_armada', $armadaB->id_armada);
        $this->assertSame(1, $rowA['jumlah_interval']);
        $this->assertSame(1, $rowB['jumlah_interval']);
        $this->assertCount(1, $rowA['jatuh_tempo']);
        $this->assertCount(1, $rowB['jatuh_tempo']);
    }

    public function test_belum_pernah_servis_true_saat_unit_tidak_punya_riwayat_sama_sekali(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 4001 AA');

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertTrue($row['belum_pernah_servis']);
    }

    public function test_belum_pernah_servis_false_setelah_ada_servis_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $armada = $this->makeArmada('B 4002 AA');
        $this->makeServis($armada->id_armada, '2026-01-01', null, null, null, 'selesai');

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertFalse($row['belum_pernah_servis']);
    }

    public function test_anchor_tanggal_beli_dipakai_saat_belum_ada_riwayat_servis_untuk_paket(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kendaraan = $this->makeJenisKendaraan('CDD');
        $paket = $this->makeInterval($kendaraan, 3, null);

        $armada = ArmadaModel::create([
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nopol'              => 'B 4003 AA',
            'status'             => 'tersedia',
            'id_jenis_kendaraan' => $kendaraan,
            'tanggal_beli'       => now()->subMonths(4)->toDateString(),
        ]);

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertTrue($row['belum_pernah_servis']);
        $this->assertCount(1, $row['jatuh_tempo']);
        $this->assertSame('lewat_jatuh_tempo', $row['jatuh_tempo'][0]['status']);
        $this->assertSame($paket, $row['jatuh_tempo'][0]['id_interval_perawatan']);
    }

    public function test_tanpa_tanggal_beli_dan_tanpa_riwayat_tetap_belum_pernah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $kendaraan = $this->makeJenisKendaraan('CDD');
        $this->makeInterval($kendaraan, 3, null);
        $armada = $this->makeArmada('B 4004 AA', 'tersedia', null, $kendaraan);

        $res = $this->getJson('/api/perawatan-armada/papan-unit');
        $res->assertStatus(200);
        $row = collect($res->json('data'))->firstWhere('id_armada', $armada->id_armada);

        $this->assertTrue($row['belum_pernah_servis']);
        $this->assertSame([], $row['jatuh_tempo']);
    }
}
