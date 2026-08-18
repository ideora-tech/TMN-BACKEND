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

class MulaiTripOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa3333-0000-4000-8000-000000000001';

    private function makeSupirDenganPengguna(string $nama): object
    {
        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'supir_' . Str::random(8),
            'email'         => Str::random(8) . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_pengguna' => $pengguna->id_pengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'jenis_sim' => 'B1', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);

        return (object) ['pengguna' => $pengguna, 'id_supir' => $idSupir];
    }

    private function berikanAksesMenuTripUntukSupir(): void
    {
        DB::table('menu')->insertOrIgnore([
            'id_menu'     => self::ID_MENU_TRIP,
            'nama_menu'   => 'Trip Monitor',
            'path'        => '/trip',
            'aktif'       => 1,
            'dibuat_pada' => now(),
        ]);
        foreach (['lihat', 'tambah'] as $aksi) {
            DB::table('izin_peran')->insertOrIgnore([
                'id_izin'     => (string) Str::uuid(),
                'kode_peran'  => 'SUPIR',
                'id_menu'     => self::ID_MENU_TRIP,
                'aksi'        => $aksi,
                'diizinkan'   => 1,
                'dibuat_pada' => now(),
            ]);
        }
    }

    private function absenHadir(string $idSupir): void
    {
        DB::table('absensi_supir')->insert([
            'id_absensi'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_supir'      => $idSupir,
            'tanggal'       => now()->toDateString(),
            'status'        => 'hadir',
            'dibuat_pada'   => now(),
        ]);
    }

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
            'nama' => 'Pagi', 'jam_mulai' => '06:00:00', 'jam_selesai' => '23:00:00',
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatJadwalHariIni(string $idProyek, string $idShift, string $idSupir): string
    {
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $idProyek, 'id_shift' => $idShift,
            'tanggal' => now()->toDateString(), 'supir' => [$idSupir],
        ])->assertStatus(200);
        return (string) DB::table('jadwal_shift')
            ->where('id_proyek', $idProyek)->where('id_supir', $idSupir)->value('id_jadwal_shift');
    }

    public function test_override_armada_saja_kepakai_saat_mulai_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $armadaTetap = $this->makeArmada('B 1000 AA');
        $armadaOverride = $this->makeArmada('B 2000 BB');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir, $armadaTetap->id_armada);
        $shift = $this->makeShift();
        $idJadwal = $this->buatJadwalHariIni($proyek->id_proyek, $shift, $supir);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_armada_override' => $armadaOverride->id_armada,
        ])->assertStatus(200);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->assertStatus(201);

        $tripAktif = DB::table('trip')
            ->join('jadwal_keberangkatan', 'jadwal_keberangkatan.id_jadwal', '=', 'trip.id_jadwal')
            ->where('jadwal_keberangkatan.id_penugasan', $penugasan->id_penugasan)
            ->first();
        $this->assertNotNull($tripAktif);

        $armadaOverrideSedangDipakai = DB::table('trip')
            ->join('jadwal_keberangkatan', 'jadwal_keberangkatan.id_jadwal', '=', 'trip.id_jadwal')
            ->join('penugasan', 'penugasan.id_penugasan', '=', 'jadwal_keberangkatan.id_penugasan')
            ->where('trip.id_trip', $tripAktif->id_trip)
            ->value('penugasan.id_armada');
        $this->assertSame($armadaTetap->id_armada, $armadaOverrideSedangDipakai);
    }

    public function test_ganti_supir_belum_punya_penugasan_dibuatkan_otomatis_lalu_ditutup(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirAsal = $this->makeSupir('Budi');
        $supirPengganti = $this->makeSupir('Andi');
        $armadaTetap = $this->makeArmada('B 1000 AA');
        $penugasanAsal = $this->makePenugasan($proyek->id_proyek, $supirAsal, $armadaTetap->id_armada);
        $shift = $this->makeShift();
        $idJadwal = $this->buatJadwalHariIni($proyek->id_proyek, $shift, $supirAsal);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supirPengganti,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('penugasan', ['id_supir' => $supirPengganti]);

        $res = $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanAsal->id_penugasan]);
        $res->assertStatus(201);

        $penugasanBaru = DB::table('penugasan')
            ->where('id_supir', $supirPengganti)->where('id_proyek', $proyek->id_proyek)
            ->whereNull('dihapus_pada')->first();
        $this->assertNotNull($penugasanBaru);
        $this->assertSame($armadaTetap->id_armada, $penugasanBaru->id_armada);
        $this->assertSame('selesai', $penugasanBaru->status);

        $tripNempelKe = DB::table('trip')
            ->join('jadwal_keberangkatan', 'jadwal_keberangkatan.id_jadwal', '=', 'trip.id_jadwal')
            ->value('jadwal_keberangkatan.id_penugasan');
        $this->assertSame($penugasanBaru->id_penugasan, $tripNempelKe);
    }

    public function test_ganti_supir_sudah_punya_penugasan_dipakai_tanpa_ditutup(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirAsal = $this->makeSupir('Budi');
        $supirPengganti = $this->makeSupir('Andi');
        $armadaPengganti = $this->makeArmada('B 3000 CC');
        $penugasanAsal = $this->makePenugasan($proyek->id_proyek, $supirAsal);
        $penugasanPengganti = $this->makePenugasan($proyek->id_proyek, $supirPengganti, $armadaPengganti->id_armada);
        $shift = $this->makeShift();
        $idJadwal = $this->buatJadwalHariIni($proyek->id_proyek, $shift, $supirAsal);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supirPengganti,
        ])->assertStatus(200);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasanAsal->id_penugasan])
            ->assertStatus(201);

        $tripNempelKe = DB::table('trip')
            ->join('jadwal_keberangkatan', 'jadwal_keberangkatan.id_jadwal', '=', 'trip.id_jadwal')
            ->value('jadwal_keberangkatan.id_penugasan');
        $this->assertSame($penugasanPengganti->id_penugasan, $tripNempelKe);

        $statusPenugasanPengganti = DB::table('penugasan')
            ->where('id_penugasan', $penugasanPengganti->id_penugasan)->value('status');
        $this->assertSame('aktif', $statusPenugasanPengganti);
    }

    public function test_titik_drop_override_kepakai_saat_mulai_trip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir, $this->makeArmada('B 1000 AA')->id_armada);
        DB::table('titik_drop_penugasan')->insert([
            'id_titik_drop' => (string) Str::uuid(), 'id_penugasan' => $penugasan->id_penugasan,
            'urutan' => 1, 'lokasi' => 'Gudang Lama', 'dibuat_pada' => now(),
        ]);
        $shift = $this->makeShift();
        $idJadwal = $this->buatJadwalHariIni($proyek->id_proyek, $shift, $supir);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'titik_drop_override' => ['Gudang Baru 1', 'Gudang Baru 2'],
        ])->assertStatus(200);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->assertStatus(201);

        $idTrip = (string) DB::table('trip')->value('id_trip');
        $lokasiTrip = DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)->whereNull('dihapus_pada')->orderBy('urutan')->pluck('lokasi')->all();
        $this->assertSame(['Gudang Baru 1', 'Gudang Baru 2'], $lokasiTrip);
    }

    public function test_tanpa_override_titik_drop_tetap_salin_dari_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir, $this->makeArmada('B 1000 AA')->id_armada);
        DB::table('titik_drop_penugasan')->insert([
            'id_titik_drop' => (string) Str::uuid(), 'id_penugasan' => $penugasan->id_penugasan,
            'urutan' => 1, 'lokasi' => 'Gudang Biasa', 'dibuat_pada' => now(),
        ]);
        $shift = $this->makeShift();
        $this->buatJadwalHariIni($proyek->id_proyek, $shift, $supir);

        $this->postJson('/api/v1/trip/mulai', ['id_penugasan' => $penugasan->id_penugasan])
            ->assertStatus(201);

        $idTrip = (string) DB::table('trip')->value('id_trip');
        $lokasiTrip = DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)->whereNull('dihapus_pada')->pluck('lokasi')->all();
        $this->assertSame(['Gudang Biasa'], $lokasiTrip);
    }

    public function test_mulai_trip_dari_mobile_tidak_terpengaruh_override_supir_pengganti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supirAsal = $this->makeSupirDenganPengguna('Budi');
        $supirPengganti = $this->makeSupir('Andi');
        $armadaTetap = $this->makeArmada('B 1000 AA');
        $penugasanAsal = $this->makePenugasan($proyek->id_proyek, $supirAsal->id_supir, $armadaTetap->id_armada);
        $shift = $this->makeShift();
        $idJadwal = $this->buatJadwalHariIni($proyek->id_proyek, $shift, $supirAsal->id_supir);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", [
            'id_shift' => $shift, 'id_supir_pengganti' => $supirPengganti,
        ])->assertStatus(200);

        $this->berikanAksesMenuTripUntukSupir();
        $this->absenHadir($supirAsal->id_supir);
        Sanctum::actingAs($supirAsal->pengguna, ['*']);

        $this->postJson('/api/v1/trip/mulai-saya', ['id_penugasan' => $penugasanAsal->id_penugasan])
            ->assertStatus(201);

        $tripNempelKe = DB::table('trip')
            ->join('jadwal_keberangkatan', 'jadwal_keberangkatan.id_jadwal', '=', 'trip.id_jadwal')
            ->value('jadwal_keberangkatan.id_penugasan');
        $this->assertSame($penugasanAsal->id_penugasan, $tripNempelKe);

        $this->assertDatabaseMissing('penugasan', ['id_supir' => $supirPengganti]);
    }
}
