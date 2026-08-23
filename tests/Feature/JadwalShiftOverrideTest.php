<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JadwalShiftOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
        ]);
    }

    private function makeSupir(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'jenis_sim' => 'B1', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Override Test',
            'dibuat_pada' => now(),
        ]);
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(8), 'nama_proyek' => 'Proyek Override Test',
        ]);
    }

    private function makePenugasan(string $idProyek, string $idSupir, ?string $idArmada = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_proyek' => $idProyek,
            'id_supir' => $idSupir, 'id_armada' => $idArmada, 'status' => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
    }

    private function makeSupirProyek(string $idProyek, string $idSupir, ?string $idPerusahaan = null): void
    {
        DB::table('supir_proyek')->insert([
            'id_supir_proyek' => (string) Str::uuid(),
            'id_perusahaan'   => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_proyek'       => $idProyek,
            'id_supir'        => $idSupir,
            'dibuat_pada'     => now(),
        ]);
    }

    private function makeShift(): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Pagi', 'jam_mulai' => '06:00:00', 'jam_selesai' => '14:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisCuti(): string
    {
        return (string) DB::table('jenis_cuti')->where('id_perusahaan', self::PERUSAHAAN_ID)->value('id_jenis_cuti');
    }

    private function buatCutiDisetujui(string $idSupir, string $tanggal): void
    {
        DB::table('pengajuan_cuti')->insert([
            'id_pengajuan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_supir' => $idSupir, 'id_jenis_cuti' => $this->makeJenisCuti(),
            'tanggal_mulai' => $tanggal, 'tanggal_selesai' => $tanggal,
            'jumlah_hari' => 1, 'status' => 'disetujui', 'dibuat_pada' => now(),
        ]);
    }

    private function actingAsRolePerusahaan(string $idPerusahaan, string $kodePeran = 'SUPERADMIN'): Pengguna
    {
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => $idPerusahaan,
            'kode_peran'    => $kodePeran,
            'username'      => 'test_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);
        Sanctum::actingAs($pengguna, ['*']);
        return $pengguna;
    }

    private function buatJadwal(string $idProyek, string $idShift, string $idSupir, string $tanggal): string
    {
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $idProyek, 'id_shift' => $idShift,
            'tanggal' => $tanggal, 'supir' => [$idSupir],
        ])->assertStatus(200);
        return (string) DB::table('jadwal_shift')
            ->where('id_proyek', $idProyek)->where('id_supir', $idSupir)->where('tanggal', $tanggal)
            ->value('id_jadwal_shift');
    }

    public function test_set_armada_override_tersimpan_dan_tampil_di_list(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $armadaAsal = $this->makeArmada('B 1000 AA');
        $armadaOverride = $this->makeArmada('B 2000 BB');
        $this->makePenugasan($proyek->id_proyek, $supir, $armadaAsal->id_armada);
        $this->makeSupirProyek($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();
        $idJadwal = $this->buatJadwal($proyek->id_proyek, $shift, $supir, $tanggal);

        $res = $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_armada_override' => $armadaOverride->id_armada,
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.id_armada_override', $armadaOverride->id_armada)
            ->assertJsonPath('data.nopol_override', 'B 2000 BB');

        $list = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyek->id_proyek}&dari={$tanggal}&sampai={$tanggal}");
        $list->assertStatus(200)->assertJsonPath('data.0.nopol_override', 'B 2000 BB');
    }

    public function test_set_supir_pengganti_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirAsal = $this->makeSupir('Budi');
        $supirPengganti = $this->makeSupir('Andi');
        $this->makeSupirProyek($proyek->id_proyek, $supirAsal);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();
        $idJadwal = $this->buatJadwal($proyek->id_proyek, $shift, $supirAsal, $tanggal);

        $res = $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supirPengganti,
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.id_supir_pengganti', $supirPengganti)
            ->assertJsonPath('data.nama_supir_pengganti', 'Andi');
    }

    public function test_tolak_supir_pengganti_sama_dengan_supir_asal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $this->makeSupirProyek($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();
        $idJadwal = $this->buatJadwal($proyek->id_proyek, $shift, $supir, $tanggal);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supir,
        ])->assertStatus(422);
    }

    public function test_tolak_supir_pengganti_sedang_cuti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirAsal = $this->makeSupir('Budi');
        $supirPengganti = $this->makeSupir('Andi');
        $this->makeSupirProyek($proyek->id_proyek, $supirAsal);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();
        $idJadwal = $this->buatJadwal($proyek->id_proyek, $shift, $supirAsal, $tanggal);
        $this->buatCutiDisetujui($supirPengganti, $tanggal);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supirPengganti,
        ])->assertStatus(422);
    }

    public function test_tolak_supir_pengganti_sudah_dijadwalkan_di_tempat_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $supirAsal = $this->makeSupir('Budi');
        $supirPengganti = $this->makeSupir('Andi');
        $this->makeSupirProyek($proyekA->id_proyek, $supirAsal);
        $this->makeSupirProyek($proyekB->id_proyek, $supirPengganti);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();
        $idJadwalA = $this->buatJadwal($proyekA->id_proyek, $shift, $supirAsal, $tanggal);
        $this->buatJadwal($proyekB->id_proyek, $shift, $supirPengganti, $tanggal);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwalA}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supirPengganti,
        ])->assertStatus(422);
    }

    public function test_set_dan_hapus_titik_drop_override(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $this->makeSupirProyek($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();
        $idJadwal = $this->buatJadwal($proyek->id_proyek, $shift, $supir, $tanggal);

        $res = $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'titik_drop_override' => ['Gudang A', 'Gudang B'],
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.titik_drop_override', ['Gudang A', 'Gudang B']);

        $res2 = $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'titik_drop_override' => null,
        ]);
        $res2->assertStatus(200)->assertJsonPath('data.titik_drop_override', []);
    }

    public function test_jadwal_dengan_trip_selesai_menolak_override(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir);
        $this->makeSupirProyek($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $tanggal = now()->toDateString();
        $idJadwal = $this->buatJadwal($proyek->id_proyek, $shift, $supir, $tanggal);

        $jadwalKeberangkatan = \App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan, 'waktu_berangkat' => now(),
        ]);
        \App\Modules\Trip\TripModel::create([
            'id_jadwal' => $jadwalKeberangkatan->id_jadwal, 'status' => 'selesai',
            'waktu_checkin' => now(), 'waktu_checkout' => now(),
        ]);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_armada_override' => (string) Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_update_dan_delete_jadwal_shift_perusahaan_lain_404(): void
    {
        $penggunaA = $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $supirA = $this->makeSupir('Budi');
        $this->makePenugasan($proyekA->id_proyek, $supirA);
        $shiftA = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert([
            'id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now(),
        ]);
        $this->actingAsRolePerusahaan($idPerusahaanLain);
        $proyekB = ProyekModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Perusahaan Lain',
        ]);
        $idSupirB = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupirB, 'id_perusahaan' => $idPerusahaanLain,
            'nama' => 'Andi', 'no_sim' => 'SIM-' . Str::random(8),
            'jenis_sim' => 'B1', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $idShiftB = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $idShiftB, 'id_perusahaan' => $idPerusahaanLain,
            'nama' => 'Pagi', 'jam_mulai' => '06:00:00', 'jam_selesai' => '14:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $this->makeSupirProyek($proyekB->id_proyek, $idSupirB, $idPerusahaanLain);
        $idJadwalB = $this->buatJadwal($proyekB->id_proyek, $idShiftB, $idSupirB, $tanggal);

        Sanctum::actingAs($penggunaA, ['*']);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwalB}", [
            'id_shift' => $shiftA,
        ])->assertStatus(404);

        $this->deleteJson("/api/v1/jadwal-shift/{$idJadwalB}")->assertStatus(404);
    }
}
