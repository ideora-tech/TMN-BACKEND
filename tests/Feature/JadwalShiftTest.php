<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
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
        $this->actingAsRole('ADMIN');
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

    public function test_dobel_tanggal_ditolak_per_item_lintas_proyek(): void
    {
        $this->actingAsRole('ADMIN');
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
        $this->actingAsRole('ADMIN');
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
        $this->actingAsRole('ADMIN');
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

    public function test_batch_create_rentang_tanggal_mengisi_semua_hari(): void
    {
        $this->actingAsRole('ADMIN');
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
        $this->actingAsRole('ADMIN');
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
        $this->actingAsRole('ADMIN');
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
        $this->actingAsRole('ADMIN');
        $this->getJson('/api/v1/jadwal-shift')->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $proyekLain = $this->makeProyek($idPerusahaanLain);

        $res = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyekLain->id_proyek}");
        $res->assertStatus(404); // proyek bukan milik perusahaan user
    }
}
