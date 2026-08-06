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

class JadwalShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(?string $idPerusahaan = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Shift Test',
        ]);
    }

    private function makeSupir(string $nama = 'Budi'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenugasan(string $idProyek, string $idSupir): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek' => $idProyek, 'id_supir' => $idSupir, 'status' => 'aktif',
        ]);
    }

    private function makeShift(string $nama = 'Pagi', string $mulai = '08:00:00', string $selesai = '16:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_batch_create_sukses_dan_list_join_shift(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirA = $this->makeSupir('Budi');
        $supirB = $this->makeSupir('Andi');
        $this->makePenugasan($proyek->id_proyek, $supirA);
        $this->makePenugasan($proyek->id_proyek, $supirB);
        $shift = $this->makeShift('Pagi');

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek,
            'id_shift'  => $shift,
            'tanggal'   => '2026-07-20',
            'supir'     => [$supirA, $supirB],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 2)
            ->assertJsonPath('data.gagal', []);

        $list = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyek->id_proyek}&dari=2026-07-01&sampai=2026-07-31");
        $list->assertStatus(200);
        $this->assertCount(2, $list->json('data'));
        $this->assertSame('Pagi', $list->json('data.0.shift_nama'));
        $this->assertSame('08:00:00', $list->json('data.0.jam_mulai'));
    }

    public function test_list_menyertakan_status_trip_berjalan_dan_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirJalan = $this->makeSupir('Budi Jalan');
        $supirSelesai = $this->makeSupir('Andi Selesai');
        $supirKosong = $this->makeSupir('Cici Kosong');
        $penugasanJalan = $this->makePenugasan($proyek->id_proyek, $supirJalan);
        $penugasanSelesai = $this->makePenugasan($proyek->id_proyek, $supirSelesai);
        $this->makePenugasan($proyek->id_proyek, $supirKosong);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-07-20', 'supir' => [$supirJalan, $supirSelesai, $supirKosong],
        ])->assertJsonPath('data.sukses', 3);

        $jadwalJalan = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasanJalan->id_penugasan,
            'waktu_berangkat' => '2026-07-20 08:00:00',
        ]);
        TripModel::create([
            'id_jadwal'     => $jadwalJalan->id_jadwal,
            'status'        => 'berjalan',
            'waktu_checkin' => '2026-07-20 08:05:00',
        ]);

        $jadwalSelesai = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasanSelesai->id_penugasan,
            'waktu_berangkat' => '2026-07-20 08:00:00',
        ]);
        TripModel::create([
            'id_jadwal'      => $jadwalSelesai->id_jadwal,
            'status'         => 'selesai',
            'waktu_checkin'  => '2026-07-20 08:05:00',
            'waktu_checkout' => '2026-07-20 17:00:00',
        ]);

        $list = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyek->id_proyek}&dari=2026-07-01&sampai=2026-07-31");

        $list->assertStatus(200);
        $data = collect($list->json('data'))->keyBy('id_supir');
        $this->assertSame('berjalan', $data[$supirJalan]['status_trip']);
        $this->assertSame('selesai', $data[$supirSelesai]['status_trip']);
        $this->assertNull($data[$supirKosong]['status_trip']);
    }

    public function test_status_trip_tidak_bocor_dari_proyek_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $supir = $this->makeSupir('Budi Lintas Proyek');
        $penugasanA = $this->makePenugasan($proyekA->id_proyek, $supir);
        $this->makePenugasan($proyekB->id_proyek, $supir);
        $shift = $this->makeShift();

        // Supir sedang trip aktif di proyek A hari itu...
        $jadwalTrip = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasanA->id_penugasan,
            'waktu_berangkat' => '2026-07-20 08:00:00',
        ]);
        TripModel::create([
            'id_jadwal'     => $jadwalTrip->id_jadwal,
            'status'        => 'berjalan',
            'waktu_checkin' => '2026-07-20 08:05:00',
        ]);

        // ...tapi jadwal shift yang dicek ada di proyek B — tidak boleh ikut ditandai.
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyekB->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);

        $list = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyekB->id_proyek}&dari=2026-07-01&sampai=2026-07-31");

        $list->assertStatus(200)
            ->assertJsonPath('data.0.status_trip', null);
    }

    public function test_dobel_tanggal_ditolak_per_item_lintas_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $this->makePenugasan($proyekA->id_proyek, $supir);
        $this->makePenugasan($proyekB->id_proyek, $supir);
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyekA->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);

        // proyek BERBEDA, tanggal sama → tetap ditolak (aturan global)
        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyekB->id_proyek, 'id_shift' => $shiftMalam,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertStringContainsString('sudah dijadwalkan', $res->json('data.gagal.0.alasan'));
        $this->assertSame(1, DB::table('jadwal_shift')->whereNull('dihapus_pada')->count());
    }

    public function test_supir_tanpa_penugasan_di_proyek_ditolak_per_item(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirLuar = $this->makeSupir('Orang Luar'); // tidak di-assign
        $shift = $this->makeShift();

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-07-20', 'supir' => [$supirLuar],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertStringContainsString('tidak ter-assign', $res->json('data.gagal.0.alasan'));
    }

    public function test_update_ganti_shift_dan_delete_membuka_tanggal_lagi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->makePenugasan($proyek->id_proyek, $supir);
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-07-21', 'supir' => [$supir],
        ]);
        $idJadwal = DB::table('jadwal_shift')->value('id_jadwal_shift');

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", ['id_shift' => $shiftMalam])
            ->assertStatus(200)
            ->assertJsonPath('data.shift_nama', 'Malam');

        $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}")->assertStatus(200);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $idJadwal]);

        // tanggal terbuka lagi setelah delete
        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-07-21', 'supir' => [$supir],
        ]);
        $res->assertJsonPath('data.sukses', 1);
    }

    public function test_hapus_jadwal_hari_ini_ditolak_jika_supir_masih_trip_aktif(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $hariIni = now()->toDateString();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $hariIni, 'supir' => [$supir],
        ]);
        $idJadwal = DB::table('jadwal_shift')->value('id_jadwal_shift');

        $jadwalKeberangkatan = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        TripModel::create([
            'id_jadwal'     => $jadwalKeberangkatan->id_jadwal,
            'status'        => 'berjalan',
            'waktu_checkin' => now(),
        ]);

        $res = $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}");

        $res->assertStatus(422);
        $this->assertStringContainsString('trip aktif', $res->json('message'));
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $idJadwal, 'dihapus_pada' => null]);
    }

    public function test_hapus_jadwal_tanggal_lain_tetap_boleh_meski_supir_trip_aktif_hari_ini(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $besok = now()->addDay()->toDateString();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $besok, 'supir' => [$supir],
        ]);
        $idJadwal = DB::table('jadwal_shift')->value('id_jadwal_shift');

        $jadwalKeberangkatan = JadwalKeberangkatanModel::create([
            'id_penugasan'    => $penugasan->id_penugasan,
            'waktu_berangkat' => now(),
        ]);
        TripModel::create([
            'id_jadwal'     => $jadwalKeberangkatan->id_jadwal,
            'status'        => 'berjalan',
            'waktu_checkin' => now(),
        ]);

        $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}")->assertStatus(200);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $idJadwal]);
    }

    public function test_batch_create_rentang_tanggal_mengisi_semua_hari(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->makePenugasan($proyek->id_proyek, $supir);
        $shift = $this->makeShift();

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek'      => $proyek->id_proyek,
            'id_shift'       => $shift,
            'tanggal'        => '2026-07-01',
            'tanggal_sampai' => '2026-07-05',
            'supir'          => [$supir],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 5)
            ->assertJsonPath('data.gagal', []);

        $this->assertSame(5, DB::table('jadwal_shift')->whereNull('dihapus_pada')->where('id_supir', $supir)->count());
    }

    public function test_rentang_dengan_hari_bentrok_dilewati_dan_dilaporkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->makePenugasan($proyek->id_proyek, $supir);
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');

        // isi dulu tanggal 3 Juli
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftMalam,
            'tanggal' => '2026-07-03', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);

        // rentang 1-5 Juli → 4 sukses, tanggal 3 gagal dengan keterangan
        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek'      => $proyek->id_proyek,
            'id_shift'       => $shiftPagi,
            'tanggal'        => '2026-07-01',
            'tanggal_sampai' => '2026-07-05',
            'supir'          => [$supir],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 4);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertSame('2026-07-03', $res->json('data.gagal.0.tanggal'));
        $this->assertStringContainsString('Malam', $res->json('data.gagal.0.alasan'));
    }

    public function test_rentang_lebih_dari_62_hari_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->makePenugasan($proyek->id_proyek, $supir);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek'      => $proyek->id_proyek,
            'id_shift'       => $shift,
            'tanggal'        => '2026-07-01',
            'tanggal_sampai' => '2026-10-01',
            'supir'          => [$supir],
        ])->assertStatus(422);
    }

    public function test_list_scoped_ke_perusahaan_dan_wajib_id_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/v1/jadwal-shift')->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $proyekLain = $this->makeProyek($idPerusahaanLain);

        $res = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyekLain->id_proyek}");
        $res->assertStatus(404); // proyek bukan milik perusahaan user
    }
}
