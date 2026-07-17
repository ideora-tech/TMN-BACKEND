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

class JadwalRuteTest extends TestCase
{
    use RefreshDatabase;

    private function makeRute(string $nama = 'Jakarta - Bandung'): object
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUT-' . Str::random(8),
            'nama_rute'     => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('rute')->where('id_rute', $id)->first();
    }

    private function makePenugasan(): PenugasanModel
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Jadwal Rute Test',
        ]);

        return PenugasanModel::create([
            'id_proyek' => $proyek->id_proyek,
            'id_armada' => (string) Str::uuid(),
            'id_supir'  => (string) Str::uuid(),
        ]);
    }

    public function test_create_jadwal_dengan_id_rute_auto_snapshot_nama_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $rute = $this->makeRute('Jakarta - Surabaya');
        $penugasan = $this->makePenugasan();

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'id_rute'         => $rute->id_rute,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_rute', $rute->id_rute)
            ->assertJsonPath('data.rute', 'Jakarta - Surabaya');
    }

    public function test_create_jadwal_dengan_id_rute_tidak_ada_ditolak_422(): void
    {
        $this->actingAsRole('ADMIN');
        $penugasan = $this->makePenugasan();

        $res = $this->postJson('/api/v1/jadwal', [
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'id_rute'         => (string) Str::uuid(),
        ]);

        $res->assertStatus(422);
    }

    public function test_update_id_rute_menyinkronkan_ulang_snapshot_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $ruteLama = $this->makeRute('Rute Lama');
        $ruteBaru = $this->makeRute('Rute Baru');
        $penugasan = $this->makePenugasan();

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'id_rute'         => $ruteLama->id_rute,
            'rute'            => $ruteLama->nama_rute,
        ]);

        $res = $this->putJson("/api/v1/jadwal/{$jadwal->id_jadwal}", [
            'id_rute' => $ruteBaru->id_rute,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_rute', $ruteBaru->id_rute)
            ->assertJsonPath('data.rute', 'Rute Baru');
    }

    public function test_update_id_rute_dikosongkan_mengosongkan_snapshot_rute(): void
    {
        $this->actingAsRole('ADMIN');
        $rute = $this->makeRute('Rute Akan Dihapus');
        $penugasan = $this->makePenugasan();

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'id_rute'         => $rute->id_rute,
            'rute'            => $rute->nama_rute,
        ]);

        $res = $this->putJson("/api/v1/jadwal/{$jadwal->id_jadwal}", [
            'id_rute' => null,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_rute', null)
            ->assertJsonPath('data.rute', null);
    }

    public function test_update_tanpa_mengirim_id_rute_tidak_mengubah_rute_existing(): void
    {
        $this->actingAsRole('ADMIN');
        $rute = $this->makeRute('Rute Tetap');
        $penugasan = $this->makePenugasan();

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'id_rute'         => $rute->id_rute,
            'rute'            => $rute->nama_rute,
        ]);

        $res = $this->putJson("/api/v1/jadwal/{$jadwal->id_jadwal}", [
            'estimasi_tiba' => '2026-08-01 12:00:00',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.id_rute', $rute->id_rute)
            ->assertJsonPath('data.rute', 'Rute Tetap');
    }

    public function test_trip_list_menampilkan_nama_rute_terbaru_walau_snapshot_lama_berbeda(): void
    {
        $this->actingAsRole('ADMIN');
        $rute = $this->makeRute('Nama Rute Lama');
        $penugasan = $this->makePenugasan();

        $jadwal = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => '2026-08-01 08:00:00',
            'id_rute'         => $rute->id_rute,
            'rute'            => 'Nama Rute Lama (snapshot basi)',
        ]);

        // nama_rute berubah di master data setelah snapshot dibuat
        DB::table('rute')->where('id_rute', $rute->id_rute)->update(['nama_rute' => 'Nama Rute Sudah Diperbarui']);

        TripModel::create(['id_jadwal' => $jadwal->id_jadwal, 'status' => 'belum_mulai']);

        $res = $this->getJson('/api/v1/trip');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.rute', 'Nama Rute Sudah Diperbarui');
    }
}
