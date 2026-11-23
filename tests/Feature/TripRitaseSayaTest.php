<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Penugasan\PenugasanModel;
use App\Modules\ProyekRute\ProyekRuteModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripRitaseSayaTest extends TestCase
{
    use RefreshDatabase;

    private const ID_MENU_TRIP = 'aaaa3333-0000-4000-8000-000000000001';

    private function actingAsSupir(): object
    {
        $this->ensurePerusahaan();
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
            'id_supir'      => $idSupir,
            'id_pengguna'   => $pengguna->id_pengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Ritase',
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

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

        Sanctum::actingAs($pengguna, ['*']);

        return (object) ['pengguna' => $pengguna, 'id_supir' => $idSupir];
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

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Ritase Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Ritase Test',
        ]);
    }

    private function makeRuteProyek(string $idProyek, string $nama = 'Jakarta - Bandung'): string
    {
        $idRute = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $idRute,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RUTE-' . Str::random(6),
            'nama_rute'     => $nama,
            'asal'          => 'Asal',
            'tujuan'        => 'Tujuan',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        ProyekRuteModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_rute'       => $idRute,
        ]);
        return $idRute;
    }

    private function makePenugasan(string $idSupir, string $idProyek, ?string $idRute, ?string $tanggal = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_proyek'      => $idProyek,
            'id_supir'       => $idSupir,
            'id_rute'        => $idRute,
            'status'         => 'aktif',
            'tanggal_tugas'  => $tanggal ?? now()->toDateString(),
            'estimasi_biaya' => 750000,
            'fee_supir'      => 120000,
        ]);
    }

    /** Jalankan trip penugasan lewat API supir sampai selesai. */
    private function selesaikanTripPenugasan(string $idPenugasan): string
    {
        $idTrip = $this->postJson('/api/trip/mulai-saya', ['id_penugasan' => $idPenugasan])
            ->assertStatus(201)
            ->json('data.id_trip');

        DB::table('laporan_perjalanan')->insert([
            'id_laporan'    => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip'       => $idTrip,
            'dibuat_pada'   => now(),
        ]);

        $this->postJson("/api/trip/{$idTrip}/checkout-saya")->assertStatus(200);

        return $idTrip;
    }

    /** @return array{0: object, 1: PenugasanModel, 2: string} */
    private function siapkanPenugasanSelesai(): array
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $idRute = $this->makeRuteProyek($proyek->id_proyek);
        $asal = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, $idRute);
        $this->absenHadir($ctx->id_supir);
        $this->selesaikanTripPenugasan($asal->id_penugasan);

        return [$ctx, $asal, $idRute];
    }

    public function test_supir_bisa_tambah_ritase_setelah_trip_selesai(): void
    {
        [$ctx, $asal, $idRute] = $this->siapkanPenugasanSelesai();

        $res = $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Pak Budi (gudang klien)',
            'catatan'      => 'Muatan susulan',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'berjalan');

        $idPenugasanBaru = $res->json('data.id_penugasan');
        $this->assertNotNull($idPenugasanBaru);
        $this->assertNotSame($asal->id_penugasan, $idPenugasanBaru);

        $baru = DB::table('penugasan')->where('id_penugasan', $idPenugasanBaru)->first();
        $this->assertSame($ctx->id_supir, $baru->id_supir);
        $this->assertSame($idRute, $baru->id_rute);
        $this->assertSame(now()->toDateString(), substr((string) $baru->tanggal_tugas, 0, 10));
        $this->assertSame('aktif', $baru->status);
        $this->assertEquals(750000, (float) $baru->estimasi_biaya);
        $this->assertEquals(120000, (float) $baru->fee_supir);
        $this->assertStringContainsString('Pak Budi (gudang klien)', $baru->keterangan);
        $this->assertStringContainsString('Muatan susulan', $baru->keterangan);
    }

    public function test_ritase_bisa_ditambah_berulang_dari_penugasan_ritase_sebelumnya(): void
    {
        [, $asal] = $this->siapkanPenugasanSelesai();

        $idPenugasanRit2 = $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(201)->json('data.id_penugasan');

        $idTripRit2 = DB::table('trip as t')
            ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
            ->where('jk.id_penugasan', $idPenugasanRit2)
            ->value('t.id_trip');
        $this->assertNotNull($idTripRit2);
        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $idTripRit2, 'dibuat_pada' => now(),
        ]);
        $this->postJson("/api/trip/{$idTripRit2}/checkout-saya")->assertStatus(200);

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $idPenugasanRit2,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(201);
    }

    public function test_trip_ritase_wajib_laporan_seperti_trip_biasa(): void
    {
        [, $asal] = $this->siapkanPenugasanSelesai();

        $idTrip = $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(201)->json('data.id_trip');

        // Supir boleh akses form laporan trip ritase-nya sendiri.
        $this->getJson("/api/trip/{$idTrip}/laporan-saya/titik-drop")->assertStatus(200);

        // Tanpa laporan, trip ritase tidak bisa diselesaikan.
        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Laporan perjalanan wajib diisi sebelum trip dapat diselesaikan');

        DB::table('laporan_perjalanan')->insert([
            'id_laporan' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_trip' => $idTrip, 'dibuat_pada' => now(),
        ]);
        $this->postJson("/api/trip/{$idTrip}/checkout-saya")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'selesai');
    }

    public function test_ritase_ditolak_jika_trip_penugasan_asal_belum_selesai(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $idRute = $this->makeRuteProyek($proyek->id_proyek);
        $asal = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, $idRute);
        $this->absenHadir($ctx->id_supir);
        $this->postJson('/api/trip/mulai-saya', ['id_penugasan' => $asal->id_penugasan])->assertStatus(201);

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(422);

        $this->assertSame(1, DB::table('penugasan')->whereNull('dihapus_pada')->count());
    }

    public function test_ritase_ditolak_untuk_penugasan_milik_supir_lain(): void
    {
        $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $idRute = $this->makeRuteProyek($proyek->id_proyek);
        $lain = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $lain, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Lain', 'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        $asal = $this->makePenugasan($lain, $proyek->id_proyek, $idRute);

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(403);
    }

    public function test_ritase_tanpa_peminta_ditolak_422(): void
    {
        [, $asal] = $this->siapkanPenugasanSelesai();

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
        ])->assertStatus(422)->assertJsonValidationErrors(['diminta_oleh']);
    }

    public function test_ritase_ditolak_tanpa_absen_dan_penugasan_baru_dirollback(): void
    {
        [$ctx, $asal] = $this->siapkanPenugasanSelesai();
        DB::table('absensi_supir')->where('id_supir', $ctx->id_supir)->delete();

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(422);

        $this->assertSame(1, DB::table('penugasan')->whereNull('dihapus_pada')->count());
    }

    public function test_ritase_ditolak_jika_operasional_sudah_siapkan_penugasan_berikutnya(): void
    {
        [$ctx, $asal, $idRute] = $this->siapkanPenugasanSelesai();
        $this->makePenugasan($ctx->id_supir, $asal->id_proyek, $idRute);

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Operasional sudah menyiapkan penugasan ritase berikutnya untuk proyek ini — mulai trip dari penugasan tersebut di tab Jadwal');
    }

    public function test_ritase_ditolak_dari_penugasan_bukan_hari_ini(): void
    {
        $ctx = $this->actingAsSupir();
        $proyek = $this->makeProyek();
        $idRute = $this->makeRuteProyek($proyek->id_proyek);
        $kemarin = $this->makePenugasan($ctx->id_supir, $proyek->id_proyek, $idRute, now()->subDay()->toDateString());
        $this->absenHadir($ctx->id_supir);

        $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $kemarin->id_penugasan,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Ritase tambahan hanya bisa dibuat dari penugasan hari ini');
    }

    public function test_ritase_dengan_rute_lain_di_proyek_memakai_tarif_rate_card(): void
    {
        [, $asal] = $this->siapkanPenugasanSelesai();
        $idRuteLain = $this->makeRuteProyek($asal->id_proyek, 'Bandung - Cirebon');
        DB::table('proyek_rute')->where('id_rute', $idRuteLain)->update(['uang_jalan' => 500000]);

        $idPenugasanBaru = $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'id_rute'      => $idRuteLain,
            'diminta_oleh' => 'Klien',
        ])->assertStatus(201)->json('data.id_penugasan');

        $baru = DB::table('penugasan')->where('id_penugasan', $idPenugasanBaru)->first();
        $this->assertSame($idRuteLain, $baru->id_rute);
        $this->assertEquals(500000, (float) $baru->estimasi_biaya);
    }

    public function test_ritase_tambahan_memberi_notifikasi_ke_operasional(): void
    {
        $idMenu = DB::table('menu')->where('path', '/penugasan')->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert([
                'id_menu' => $idMenu, 'nama_menu' => 'penugasan', 'path' => '/penugasan', 'aktif' => 1, 'dibuat_pada' => now(),
            ]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'OPS_TEST',
            'id_menu' => $idMenu, 'aksi' => 'tambah', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
        $this->ensurePerusahaan();
        $operasional = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => 'OPS_TEST', 'username' => 'u_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);

        [, $asal] = $this->siapkanPenugasanSelesai();

        $idPenugasanBaru = $this->postJson('/api/trip/ritase-saya', [
            'id_penugasan' => $asal->id_penugasan,
            'diminta_oleh' => 'Pak Budi',
        ])->assertStatus(201)->json('data.id_penugasan');

        $notif = DB::table('notifikasi')
            ->where('tipe', 'ritase_tambahan_supir')
            ->where('id_pengguna', $operasional->id_pengguna)
            ->first();

        $this->assertNotNull($notif);
        $this->assertSame($idPenugasanBaru, $notif->referensi_id);
        $this->assertSame('/penugasan/' . $idPenugasanBaru, $notif->link);
        $this->assertStringContainsString('Supir Ritase', $notif->isi);
        $this->assertStringContainsString('Pak Budi', $notif->isi);
    }
}
