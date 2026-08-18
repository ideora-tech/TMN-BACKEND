<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlokasiArmadaOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function makeArmada(string $nopol): ArmadaModel
    {
        return ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => $nopol, 'merk' => 'Hino',
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
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Test',
            'dibuat_pada' => now(),
        ]);
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'kode_proyek' => 'PRJ-' . Str::random(8), 'nama_proyek' => 'Proyek Test',
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

    public function test_override_armada_menang_atas_armada_tetap_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $armadaTetap = $this->makeArmada('B 1000 AA');
        $armadaOverride = $this->makeArmada('B 2000 BB');
        $this->makePenugasan($proyek->id_proyek, $supir, $armadaTetap->id_armada);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $tanggal, 'supir' => [$supir],
        ])->assertStatus(200);

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $supir, 'tanggal' => $tanggal,
            'id_armada' => $armadaTetap->id_armada, 'sumber' => 'penugasan',
        ]);

        $idJadwal = (string) DB::table('jadwal_shift')
            ->where('id_proyek', $proyek->id_proyek)->where('id_supir', $supir)->value('id_jadwal_shift');
        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_armada_override' => $armadaOverride->id_armada,
        ])->assertStatus(200);

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $supir, 'tanggal' => $tanggal,
            'id_armada' => $armadaOverride->id_armada, 'sumber' => 'override_manual',
        ]);
        $this->assertDatabaseMissing('alokasi_armada', [
            'id_supir' => $supir, 'tanggal' => $tanggal,
            'id_armada' => $armadaTetap->id_armada, 'dihapus_pada' => null,
        ]);
    }

    public function test_tanggal_lain_tanpa_override_tetap_pakai_logika_lama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $armadaTetap = $this->makeArmada('B 1000 AA');
        $this->makePenugasan($proyek->id_proyek, $supir, $armadaTetap->id_armada);
        $shift = $this->makeShift();
        $tanggalOverride = now()->addDays(5)->toDateString();
        $tanggalNormal = now()->addDays(6)->toDateString();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $tanggalOverride, 'tanggal_sampai' => $tanggalNormal, 'supir' => [$supir],
        ])->assertStatus(200);

        $idJadwalOverride = (string) DB::table('jadwal_shift')
            ->where('id_proyek', $proyek->id_proyek)->where('id_supir', $supir)
            ->where('tanggal', $tanggalOverride)->value('id_jadwal_shift');
        $armadaOverride = $this->makeArmada('B 2000 BB');
        $this->putJson("/api/v1/jadwal-shift/{$idJadwalOverride}", [
            'id_shift' => $shift, 'id_armada_override' => $armadaOverride->id_armada,
        ])->assertStatus(200);

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $supir, 'tanggal' => $tanggalNormal,
            'id_armada' => $armadaTetap->id_armada, 'sumber' => 'penugasan',
        ]);
    }
}
