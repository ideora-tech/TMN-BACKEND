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

/**
 * Override armada shift (`id_armada_override`) tidak lagi memicu alokasi
 * armada otomatis di JadwalShiftService::updateShift — kolom override tetap
 * tersimpan & tampil di papan sebagai info manual, tapi sejak Task 7
 * TripService sudah tidak lagi membacanya saat memulai trip (lihat
 * MulaiTripOverrideTest), dan tabel alokasi_armada tidak lagi ditulis dari
 * titik ini.
 */
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

    private function makeSupirProyek(string $idProyek, string $idSupir): void
    {
        DB::table('supir_proyek')->insert([
            'id_supir_proyek' => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
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

    public function test_update_shift_dengan_armada_override_tidak_lagi_membuat_alokasi_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $armadaTetap = $this->makeArmada('B 1000 AA');
        $armadaOverride = $this->makeArmada('B 2000 BB');
        $this->makePenugasan($proyek->id_proyek, $supir, $armadaTetap->id_armada);
        $this->makeSupirProyek($proyek->id_proyek, $supir);
        $shift = $this->makeShift();
        $tanggal = now()->addDays(5)->toDateString();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $tanggal, 'supir' => [$supir],
        ])->assertStatus(200);

        $idJadwal = (string) DB::table('jadwal_shift')
            ->where('id_proyek', $proyek->id_proyek)->where('id_supir', $supir)->value('id_jadwal_shift');
        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_armada_override' => $armadaOverride->id_armada,
        ])->assertStatus(200)
            ->assertJsonPath('data.id_armada_override', $armadaOverride->id_armada)
            ->assertJsonPath('data.nopol_override', 'B 2000 BB');

        $this->assertSame(0, DB::table('alokasi_armada')->count());
    }
}
