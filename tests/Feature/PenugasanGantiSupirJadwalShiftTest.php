<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ArmadaVendor\ArmadaVendorModel;
use App\Modules\KontrakVendor\KontrakVendorModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Vendor\VendorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Papan Jadwal mengambil baris dari penugasan aktif, bukan dari jadwal_shift
 * langsung — ganti supir di Edit Penugasan tanpa penanganan khusus membuat
 * jadwal_shift supir lama jadi nyangkut tak terlihat. PenugasanService::update()
 * kini memindahkan kepemilikan jadwal_shift mulai hari ini ke supir baru.
 */
class PenugasanGantiSupirJadwalShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Ganti Supir Test',
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

    private function makeShift(): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Pagi', 'jam_mulai' => '08:00:00', 'jam_selesai' => '16:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJadwalShift(string $idProyek, string $idSupir, string $idShift, string $tanggal): string
    {
        $id = (string) Str::uuid();
        DB::table('jadwal_shift')->insert([
            'id_jadwal_shift' => $id, 'id_proyek' => $idProyek, 'id_shift' => $idShift,
            'id_supir' => $idSupir, 'tanggal' => $tanggal, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_ganti_supir_memindahkan_jadwal_mulai_hari_ini_dan_membiarkan_jadwal_lampau(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirLama = $this->makeSupir('Supir Lama');
        $supirBaru = $this->makeSupir('Supir Baru');
        $shift = $this->makeShift();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek, 'id_supir' => $supirLama, 'status' => 'aktif',
        ])->json('data.id_penugasan');

        $kemarin = now()->subDay()->toDateString();
        $hariIni = now()->toDateString();
        $besok   = now()->addDay()->toDateString();

        $jadwalKemarin = $this->makeJadwalShift($proyek->id_proyek, $supirLama, $shift, $kemarin);
        $jadwalHariIni = $this->makeJadwalShift($proyek->id_proyek, $supirLama, $shift, $hariIni);
        $jadwalBesok   = $this->makeJadwalShift($proyek->id_proyek, $supirLama, $shift, $besok);

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['id_supir' => $supirBaru])
            ->assertStatus(200);

        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalKemarin, 'id_supir' => $supirLama]);
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalHariIni, 'id_supir' => $supirBaru]);
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalBesok, 'id_supir' => $supirBaru]);
    }

    public function test_ganti_supir_melewati_tanggal_yang_bentrok_dengan_jadwal_supir_baru_di_proyek_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $supirLama = $this->makeSupir('Supir Lama');
        $supirBaru = $this->makeSupir('Supir Baru');
        $shift = $this->makeShift();

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyekA->id_proyek, 'id_supir' => $supirLama, 'status' => 'aktif',
        ])->json('data.id_penugasan');

        $hariIni = now()->toDateString();
        $besok   = now()->addDay()->toDateString();

        $jadwalHariIni = $this->makeJadwalShift($proyekA->id_proyek, $supirLama, $shift, $hariIni);
        $jadwalBesokLama = $this->makeJadwalShift($proyekA->id_proyek, $supirLama, $shift, $besok);
        // supirBaru sudah punya jadwal di proyek lain untuk besok — bentrok, aturan 1 shift/hari global.
        $this->makeJadwalShift($proyekB->id_proyek, $supirBaru, $shift, $besok);

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['id_supir' => $supirBaru])
            ->assertStatus(200);

        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalHariIni, 'id_supir' => $supirBaru]);
        // Tanggal bentrok dilewati — tetap milik supir lama, tidak dipindah.
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalBesokLama, 'id_supir' => $supirLama]);
    }

    public function test_ganti_armada_saja_tanpa_ganti_supir_tidak_menyentuh_jadwal_shift(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Supir Tetap');
        $shift = $this->makeShift();

        $idArmadaBaru = (string) Str::uuid();
        DB::table('armada')->insert([
            'id_armada' => $idArmadaBaru, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol' => 'B ' . random_int(1000, 9999) . ' XYZ', 'status' => 'tersedia', 'dibuat_pada' => now(),
        ]);

        $idPenugasan = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek, 'id_supir' => $supir, 'status' => 'aktif',
        ])->json('data.id_penugasan');

        $hariIni = now()->toDateString();
        $jadwalHariIni = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $hariIni);

        $this->putJson("/api/v1/penugasan/{$idPenugasan}", ['id_armada' => $idArmadaBaru])
            ->assertStatus(200);

        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalHariIni, 'id_supir' => $supir]);
    }

    public function test_penugasan_baru_menghapus_jadwal_orphan_dari_penugasan_lama_yang_sudah_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir  = $this->makeSupir('Wawan');
        $shift  = $this->makeShift();

        $idPenugasanLama = $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek, 'id_supir' => $supir, 'status' => 'aktif',
        ])->json('data.id_penugasan');

        $kemarin = now()->subDay()->toDateString();
        $hariIni = now()->toDateString();
        $besok   = now()->addDay()->toDateString();

        $jadwalKemarin = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $kemarin);
        $jadwalHariIni = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $hariIni);
        $jadwalBesok   = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $besok);

        $this->putJson("/api/v1/penugasan/{$idPenugasanLama}", ['status' => 'selesai'])
            ->assertStatus(200);

        // Penugasan baru buat supir yang sama di proyek yang sama, hari yang sama.
        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek, 'id_supir' => $supir, 'status' => 'aktif',
        ])->assertStatus(201);

        // Riwayat sebelum hari ini tetap utuh.
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalKemarin, 'dihapus_pada' => null]);
        // Jadwal nyangkut dari penugasan lama (hari ini & depan) sudah dibersihkan.
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $jadwalHariIni]);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $jadwalBesok]);

        // Assign shift baru hari ini untuk supir itu tidak lagi kejegal aturan "sudah dijadwalkan".
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => $hariIni, 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1)->assertJsonPath('data.gagal', []);
    }

    public function test_penugasan_baru_tidak_menghapus_jadwal_jika_masih_ada_penugasan_aktif_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir  = $this->makeSupir('Wawan');
        $shift  = $this->makeShift();

        $vendor  = VendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'kode_vendor' => 'VDR-' . Str::random(8), 'nama_vendor' => 'Vendor Unit Only',
        ]);
        $kontrak = KontrakVendorModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID, 'id_vendor' => $vendor->id_vendor, 'mekanisme' => 'unit_only',
        ]);
        $unit    = ArmadaVendorModel::create([
            'id_vendor' => $vendor->id_vendor, 'nopol' => 'B ' . random_int(1000, 9999) . ' VD',
        ]);

        // Penugasan lama: unit_only vendor, tetap AKTIF (belum ditutup) — pakai supir yang sama.
        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek, 'sumber' => 'vendor',
            'id_kontrak_vendor' => $kontrak->id_kontrak_vendor, 'id_armada_vendor' => $unit->id_armada_vendor,
            'id_supir' => $supir, 'status' => 'aktif',
        ])->assertStatus(201);

        $hariIni = now()->toDateString();
        $jadwalHariIni = $this->makeJadwalShift($proyek->id_proyek, $supir, $shift, $hariIni);

        // Penugasan baru (internal) buat supir yang sama, proyek yang sama.
        $this->postJson('/api/v1/penugasan', [
            'id_proyek' => $proyek->id_proyek, 'id_supir' => $supir, 'status' => 'aktif',
        ])->assertStatus(201);

        // Penugasan vendor unit_only tadi masih aktif — jadwal yang ada memang masih valid, tidak boleh dihapus.
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $jadwalHariIni, 'dihapus_pada' => null]);
    }
}
