<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenugasanSinkronProyekTest extends TestCase
{
    use RefreshDatabase;

    private string $idRute;

    private function makeProyek(string $mulai, ?string $selesai): ProyekModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => (string) Str::uuid(),
            'kode_proyek'     => 'PRJ-' . Str::random(8),
            'nama_proyek'     => 'Proyek Sinkron',
            'tanggal_mulai'   => $mulai,
            'tanggal_selesai' => $selesai,
        ]);

        $this->idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute' => $this->idRute, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute' => 'RT-' . Str::random(6), 'nama_rute' => 'Bekasi - Jakarta', 'dibuat_pada' => now(),
        ]);
        DB::table('proyek_rute')->insert([
            'id_proyek_rute' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $this->idRute, 'uang_jalan' => null, 'dibuat_pada' => now(),
        ]);

        return $proyek;
    }

    private function makeUnit(string $nopol, string $namaSupir): array
    {
        $armada = ArmadaModel::create(['id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => $nopol, 'merk' => 'Hino']);
        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => $namaSupir,
            'no_sim' => 'SIM-' . Str::random(8), 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        return ['id_armada' => $armada->id_armada, 'id_supir' => $idSupir];
    }

    private function tugaskan(ProyekModel $proyek, array $unit, array $tanggal, ?string $keterangan = null): array
    {
        return array_map(fn ($t) => PenugasanModel::create([
            'id_proyek'     => $proyek->id_proyek,
            'id_rute'       => $this->idRute,
            'id_armada'     => $unit['id_armada'],
            'id_supir'      => $unit['id_supir'],
            'sumber'        => 'internal',
            'tanggal_tugas' => $t,
            'status'        => 'aktif',
            'keterangan'    => $keterangan,
        ])->id_penugasan, $tanggal);
    }

    private function tanggalUnit(array $unit): array
    {
        return DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_armada', $unit['id_armada'])
            ->orderBy('tanggal_tugas')
            ->pluck('tanggal_tugas')
            ->map(fn ($t) => substr((string) $t, 0, 10))
            ->all();
    }

    public function test_pratinjau_perpanjang_dari_satu_hari_tanpa_mengubah_data(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-11', null);
        $unit = $this->makeUnit('B 9001 TMN', 'Ahmad Fauzi');
        $this->tugaskan($proyek, $unit, ['2026-09-11']);

        $res = $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan/pratinjau", [
            'lama_mulai' => '2026-09-11', 'lama_selesai' => null,
            'baru_mulai' => '2026-09-11', 'baru_selesai' => '2026-09-15',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.total_tambah', 4)
            ->assertJsonPath('data.total_hapus', 0)
            ->assertJsonPath('data.tambah.0.nopol', 'B 9001 TMN')
            ->assertJsonPath('data.tambah.0.nama_supir', 'Ahmad Fauzi')
            ->assertJsonPath('data.tambah.0.dari', '2026-09-12')
            ->assertJsonPath('data.tambah.0.sampai', '2026-09-15')
            ->assertJsonPath('data.tambah.0.jumlah_hari', 4);
        $this->assertSame(['2026-09-11'], $this->tanggalUnit($unit));
    }

    public function test_jalankan_perpanjang_hanya_unit_yang_berjalan_sampai_akhir_periode_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-11', '2026-09-15');
        $unitA = $this->makeUnit('B 9001 TMN', 'Ahmad Fauzi');
        $unitB = $this->makeUnit('B 9002 TMN', 'Budi Santoso');
        $this->tugaskan($proyek, $unitA, ['2026-09-11', '2026-09-12'], 'Muat pagi');
        $this->tugaskan($proyek, $unitB, ['2026-09-11']);

        $res = $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan", [
            'lama_mulai' => '2026-09-11', 'lama_selesai' => '2026-09-12',
            'baru_mulai' => '2026-09-11', 'baru_selesai' => '2026-09-15',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.dibuat', 3)
            ->assertJsonPath('data.dihapus', 0);
        $this->assertSame(['2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15'], $this->tanggalUnit($unitA));
        $this->assertSame(['2026-09-11'], $this->tanggalUnit($unitB));
        $this->assertSame(5, DB::table('penugasan')->where('id_armada', $unitA['id_armada'])->where('keterangan', 'Muat pagi')->count());
    }

    public function test_jalankan_tanggal_mulai_dimajukan_menambah_di_awal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-09', '2026-09-12');
        $unit = $this->makeUnit('B 9001 TMN', 'Ahmad Fauzi');
        $this->tugaskan($proyek, $unit, ['2026-09-11', '2026-09-12']);

        $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan", [
            'lama_mulai' => '2026-09-11', 'lama_selesai' => '2026-09-12',
            'baru_mulai' => '2026-09-09', 'baru_selesai' => '2026-09-12',
        ])->assertStatus(200)->assertJsonPath('data.dibuat', 2);

        $this->assertSame(['2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12'], $this->tanggalUnit($unit));
    }

    public function test_jalankan_perpendek_menghapus_di_luar_periode_dan_melewati_yang_punya_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-11', '2026-09-12');
        $unit = $this->makeUnit('B 9001 TMN', 'Ahmad Fauzi');
        $ids = $this->tugaskan($proyek, $unit, ['2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15']);

        $jadwal = JadwalKeberangkatanModel::create(['id_penugasan' => $ids[3], 'waktu_berangkat' => '2026-09-14 08:00:00']);
        TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'selesai']);

        $pratinjau = $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan/pratinjau", [
            'lama_mulai' => '2026-09-11', 'lama_selesai' => '2026-09-15',
            'baru_mulai' => '2026-09-11', 'baru_selesai' => '2026-09-12',
        ]);
        $pratinjau->assertStatus(200)
            ->assertJsonPath('data.total_tambah', 0)
            ->assertJsonPath('data.total_hapus', 2)
            ->assertJsonPath('data.total_terkunci', 1);

        $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan", [
            'lama_mulai' => '2026-09-11', 'lama_selesai' => '2026-09-15',
            'baru_mulai' => '2026-09-11', 'baru_selesai' => '2026-09-12',
        ])->assertStatus(200)
            ->assertJsonPath('data.dihapus', 2)
            ->assertJsonPath('data.terkunci', 1);

        $this->assertSame(['2026-09-11', '2026-09-12', '2026-09-14'], $this->tanggalUnit($unit));
    }

    public function test_perpanjang_lebih_dari_62_hari_dipecah_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-01-01', '2026-03-31');
        $unit = $this->makeUnit('B 9001 TMN', 'Ahmad Fauzi');
        $this->tugaskan($proyek, $unit, ['2026-01-01']);

        $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan", [
            'lama_mulai' => '2026-01-01', 'lama_selesai' => null,
            'baru_mulai' => '2026-01-01', 'baru_selesai' => '2026-03-31',
        ])->assertStatus(200)->assertJsonPath('data.dibuat', 89);

        $this->assertCount(90, $this->tanggalUnit($unit));
    }

    public function test_jalankan_ditolak_bila_periode_baru_tidak_sama_dengan_tanggal_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-11', '2026-09-15');

        $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan", [
            'lama_mulai' => '2026-09-11', 'lama_selesai' => null,
            'baru_mulai' => '2026-09-11', 'baru_selesai' => '2026-09-20',
        ])->assertStatus(422);
    }

    public function test_validasi_periode_dan_proyek_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-11', null);

        $this->postJson("/api/proyek/{$proyek->id_proyek}/sinkron-penugasan/pratinjau", [
            'lama_mulai' => '2026-09-11', 'baru_mulai' => '2026-09-15', 'baru_selesai' => '2026-09-12',
        ])->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $proyekLain = ProyekModel::create([
            'id_perusahaan' => $idPerusahaanLain, 'id_klien' => (string) Str::uuid(),
            'kode_proyek' => 'PRJ-LAIN', 'nama_proyek' => 'Proyek Lain', 'tanggal_mulai' => '2026-09-11',
        ]);

        $this->postJson("/api/proyek/{$proyekLain->id_proyek}/sinkron-penugasan/pratinjau", [
            'lama_mulai' => '2026-09-11', 'baru_mulai' => '2026-09-11', 'baru_selesai' => '2026-09-15',
        ])->assertStatus(404);
    }

    public function test_buat_penugasan_ulang_unit_yang_sama_melewati_tanggal_yang_sudah_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek('2026-09-11', '2026-09-13');
        $unit = $this->makeUnit('B 9001 TMN', 'Ahmad Fauzi');
        $this->tugaskan($proyek, $unit, ['2026-09-11']);

        $res = $this->postJson('/api/penugasan/harian', [
            'tanggal' => '2026-09-11', 'tanggal_sampai' => '2026-09-13',
            'id_armada' => $unit['id_armada'], 'id_supir' => $unit['id_supir'],
            'id_proyek' => $proyek->id_proyek, 'id_rute' => $this->idRute,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 2)
            ->assertJsonPath('data.dilewati', ['2026-09-11']);
        $this->assertCount(0, $res->json('data.gagal'));
        $this->assertSame(['2026-09-11', '2026-09-12', '2026-09-13'], $this->tanggalUnit($unit));
    }
}
