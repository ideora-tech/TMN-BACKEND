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

class AlokasiArmadaTest extends TestCase
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

    private function makeSupir(string $nama, ?string $idArmadaDefault = null): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'          => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_armada_default' => $idArmadaDefault,
            'nama'              => $nama,
            'no_sim'            => 'SIM-' . Str::random(8),
            'jenis_sim'         => 'B1',
            'status'            => 'aktif',
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function makeProyek(): ProyekModel
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlien,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Alokasi Test',
            'dibuat_pada'   => now(),
        ]);

        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Alokasi Test',
        ]);
    }

    private function makePenugasan(string $idSupir, string $idProyek, ?string $idArmada = null): PenugasanModel
    {
        return PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $idProyek,
            'id_supir'      => $idSupir,
            'id_armada'     => $idArmada,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
    }

    private function makeShift(): string
    {
        $idShift = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift'      => $idShift,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Pagi',
            'jam_mulai'     => '06:00:00',
            'jam_selesai'   => '14:00:00',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $idShift;
    }

    private function makeCuti(string $idSupir, string $dari, string $sampai): void
    {
        $idJenis = (string) Str::uuid();
        DB::table('jenis_cuti')->insertOrIgnore([
            'id_jenis_cuti' => $idJenis,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama_jenis'    => 'Cuti Test',
            'dibuat_pada'   => now(),
        ]);
        DB::table('pengajuan_cuti')->insert([
            'id_pengajuan'    => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_supir'        => $idSupir,
            'id_jenis_cuti'   => $idJenis,
            'tanggal_mulai'   => $dari,
            'tanggal_selesai' => $sampai,
            'jumlah_hari'     => 1,
            'status'          => 'disetujui',
            'dibuat_pada'     => now(),
        ]);
    }

    private function buatJadwal(string $idProyek, string $idShift, array $idSupir, string $tanggal): void
    {
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $idProyek,
            'id_shift'  => $idShift,
            'tanggal'   => $tanggal,
            'supir'     => $idSupir,
        ])->assertStatus(200);
    }

    public function test_supir_dengan_armada_default_dapat_alokasi_default(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 1000 AA');
        $idSupir = $this->makeSupir('Supir Punya Mobil');
        $penugasan = $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'  => $idSupir,
            'tanggal'   => '2026-08-10',
            'id_armada' => $armada->id_armada,
            'sumber'    => 'default',
        ]);
        $this->assertSame($armada->id_armada, DB::table('penugasan')->where('id_penugasan', $penugasan->id_penugasan)->value('id_armada'));
    }

    public function test_supir_shift_otomatis_pinjam_mobil_pemilik_cuti(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 2000 BB');
        $pemilik = $this->makeSupir('Pemilik Cuti');
        $this->makePenugasan($pemilik, $proyek->id_proyek, $armada->id_armada);
        $this->makeCuti($pemilik, '2026-08-10', '2026-08-12');

        $idShiftSupir = $this->makeSupir('Supir Shift');
        $penugasan = $this->makePenugasan($idShiftSupir, $proyek->id_proyek);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyek->id_proyek, $idShift, [$idShiftSupir], '2026-08-10');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'        => $idShiftSupir,
            'tanggal'         => '2026-08-10',
            'id_armada'       => $armada->id_armada,
            'id_pemilik_asal' => $pemilik,
            'sumber'          => 'otomatis',
            'keterangan'      => 'Pemilik cuti',
        ]);
        $this->assertNull(DB::table('penugasan')->where('id_penugasan', $penugasan->id_penugasan)->value('id_armada'));
    }

    public function test_mobil_pemilik_yang_masuk_kerja_tidak_dipinjam(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 3000 CC');
        $pemilik = $this->makeSupir('Pemilik Masuk');
        $this->makePenugasan($pemilik, $proyek->id_proyek, $armada->id_armada);

        $idShiftSupir = $this->makeSupir('Supir Shift');
        $this->makePenugasan($idShiftSupir, $proyek->id_proyek);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyek->id_proyek, $idShift, [$pemilik, $idShiftSupir], '2026-08-10');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'  => $pemilik,
            'id_armada' => $armada->id_armada,
            'sumber'    => 'default',
        ]);
        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'   => $idShiftSupir,
            'id_armada'  => null,
            'keterangan' => 'Tidak ada armada tersedia',
        ]);
    }

    public function test_mobil_proyek_lain_tidak_dipinjam_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();

        $armadaLain = $this->makeArmada('A 1 ZZ'); // nopol paling kecil, tapi milik proyek B
        $pemilikLain = $this->makeSupir('Supir Proyek Lain');
        $this->makePenugasan($pemilikLain, $proyekB->id_proyek, $armadaLain->id_armada);

        $idShiftSupir = $this->makeSupir('Supir Shift A');
        $this->makePenugasan($idShiftSupir, $proyekA->id_proyek);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyekA->id_proyek, $idShift, [$idShiftSupir], '2026-08-10');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'   => $idShiftSupir,
            'tanggal'    => '2026-08-10',
            'id_armada'  => null,
            'keterangan' => 'Tidak ada armada tersedia',
        ]);
    }

    public function test_satu_mobil_nganggur_tidak_dialokasikan_dobel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 4000 DD');
        $pemilik = $this->makeSupir('Pemilik Cuti');
        $this->makePenugasan($pemilik, $proyek->id_proyek, $armada->id_armada);
        $this->makeCuti($pemilik, '2026-08-10', '2026-08-10');

        $shiftA = $this->makeSupir('Shift A');
        $shiftB = $this->makeSupir('Shift B');
        $this->makePenugasan($shiftA, $proyek->id_proyek);
        $this->makePenugasan($shiftB, $proyek->id_proyek);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyek->id_proyek, $idShift, [$shiftA, $shiftB], '2026-08-10');

        $dapat = DB::table('alokasi_armada')
            ->whereNull('dihapus_pada')
            ->where('tanggal', '2026-08-10')
            ->where('id_armada', $armada->id_armada)
            ->count();
        $this->assertSame(1, $dapat);
    }

    public function test_list_dan_override_manual(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 5000 EE');
        $armadaB = $this->makeArmada('B 6000 FF');
        $pemilikA = $this->makeSupir('Pemilik A');
        $pemilikB = $this->makeSupir('Pemilik B');
        $this->makePenugasan($pemilikA, $proyek->id_proyek, $armadaA->id_armada);
        $this->makePenugasan($pemilikB, $proyek->id_proyek, $armadaB->id_armada);
        $this->makeCuti($pemilikA, '2026-08-10', '2026-08-10');
        $this->makeCuti($pemilikB, '2026-08-10', '2026-08-10');

        $idShiftSupir = $this->makeSupir('Supir Shift');
        $penugasan = $this->makePenugasan($idShiftSupir, $proyek->id_proyek);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idShiftSupir], '2026-08-10');

        $list = $this->getJson('/api/v1/alokasi-armada?tanggal_dari=2026-08-10&tanggal_sampai=2026-08-10');
        $list->assertStatus(200)->assertJsonCount(1, 'data');
        $idAlokasi = $list->json('data.0.id_alokasi');
        $this->assertSame('B 5000 EE', $list->json('data.0.armada_nopol'));

        $this->putJson("/api/v1/alokasi-armada/{$idAlokasi}", ['id_armada' => $armadaB->id_armada])
            ->assertStatus(200)
            ->assertJsonPath('data.sumber', 'manual');

        $this->assertNull(DB::table('penugasan')->where('id_penugasan', $penugasan->id_penugasan)->value('id_armada'));
    }

    public function test_override_ke_armada_yang_sudah_dipakai_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 7000 GG');
        $armadaB = $this->makeArmada('B 8000 HH');
        $supirA = $this->makeSupir('Supir A');
        $supirB = $this->makeSupir('Supir B');
        $this->makePenugasan($supirA, $proyek->id_proyek, $armadaA->id_armada);
        $this->makePenugasan($supirB, $proyek->id_proyek, $armadaB->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$supirA, $supirB], '2026-08-10');

        $idAlokasiA = DB::table('alokasi_armada')->where('id_supir', $supirA)->whereNull('dihapus_pada')->value('id_alokasi');

        $this->putJson("/api/v1/alokasi-armada/{$idAlokasiA}", ['id_armada' => $armadaB->id_armada])
            ->assertStatus(422);
    }

    public function test_ubah_armada_penugasan_menghitung_ulang_alokasi_mendatang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 9600 OO');
        $idSupir = $this->makeSupir('Supir Berubah');
        $penugasan = $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-20');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $idSupir, 'tanggal' => '2026-08-20',
            'id_armada' => $armada->id_armada, 'sumber' => 'default',
        ]);

        $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", ['id_armada' => null])
            ->assertStatus(200);

        // mobil dilepas dari penugasan → jadi armada tanpa pemilik → dipinjamkan otomatis
        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'   => $idSupir,
            'tanggal'    => '2026-08-20',
            'id_armada'  => $armada->id_armada,
            'sumber'     => 'otomatis',
            'keterangan' => 'Armada tanpa pemilik',
        ]);
        $this->assertNull(DB::table('penugasan')->where('id_penugasan', $penugasan->id_penugasan)->value('id_armada'));
    }

    public function test_riwayat_per_unit_menyertakan_baris_yang_digantikan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 9700 PP');
        $armadaB = $this->makeArmada('B 9800 QQ');
        $idSupir = $this->makeSupir('Supir Riwayat');
        $pemilikB = $this->makeSupir('Pemilik B');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armadaA->id_armada);
        $this->makePenugasan($pemilikB, $proyek->id_proyek, $armadaB->id_armada);
        $this->makeCuti($pemilikB, '2026-08-10', '2026-08-10');
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $idAlokasi = DB::table('alokasi_armada')->where('id_supir', $idSupir)->whereNull('dihapus_pada')->value('id_alokasi');
        $this->putJson("/api/v1/alokasi-armada/{$idAlokasi}", ['id_armada' => $armadaB->id_armada])->assertStatus(200);

        // riwayat armada A: satu baris (default) yang sudah digantikan
        $res = $this->getJson("/api/v1/alokasi-armada/riwayat?id_armada={$armadaA->id_armada}");
        $res->assertStatus(200)
            ->assertJsonPath('data.armada.nopol', 'B 9700 PP')
            ->assertJsonCount(1, 'data.items');
        $this->assertNotNull($res->json('data.items.0.dihapus_pada'));

        // riwayat armada B: baris manual yang berlaku
        $resB = $this->getJson("/api/v1/alokasi-armada/riwayat?id_armada={$armadaB->id_armada}");
        $resB->assertStatus(200)->assertJsonCount(1, 'data.items');
        $this->assertSame('manual', $resB->json('data.items.0.sumber'));
        $this->assertNull($resB->json('data.items.0.dihapus_pada'));
    }

    public function test_laporan_riwayat_per_armada_bisa_diunduh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 9300 LL');
        $idSupir = $this->makeSupir('Supir Laporan');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $this->get("/api/v1/alokasi-armada/export/excel?id_armada={$armada->id_armada}")
            ->assertStatus(200);
        $this->get("/api/v1/alokasi-armada/export/pdf?id_armada={$armada->id_armada}")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_list_bisa_difilter_per_armada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 9400 MM');
        $armadaB = $this->makeArmada('B 9500 NN');
        $supirA = $this->makeSupir('Supir AA');
        $supirB = $this->makeSupir('Supir BB');
        $this->makePenugasan($supirA, $proyek->id_proyek, $armadaA->id_armada);
        $this->makePenugasan($supirB, $proyek->id_proyek, $armadaB->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$supirA, $supirB], '2026-08-10');

        $this->getJson("/api/v1/alokasi-armada?id_armada={$armadaA->id_armada}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.armada_nopol', 'B 9400 MM');
    }

    public function test_hapus_jadwal_menghapus_alokasi_manual_juga(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 9100 JJ');
        $armadaB = $this->makeArmada('B 9200 KK');
        $idSupir = $this->makeSupir('Supir Utama');
        $pemilikB = $this->makeSupir('Pemilik B');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armadaA->id_armada);
        $this->makePenugasan($pemilikB, $proyek->id_proyek, $armadaB->id_armada);
        $this->makeCuti($pemilikB, '2026-08-10', '2026-08-10');
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $idAlokasi = DB::table('alokasi_armada')->where('id_supir', $idSupir)->whereNull('dihapus_pada')->value('id_alokasi');
        $this->putJson("/api/v1/alokasi-armada/{$idAlokasi}", ['id_armada' => $armadaB->id_armada])
            ->assertStatus(200)
            ->assertJsonPath('data.sumber', 'manual');

        $idJadwal = DB::table('jadwal_shift')->where('id_supir', $idSupir)->value('id_jadwal_shift');
        $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}")->assertStatus(200);

        $this->assertSoftDeleted('alokasi_armada', ['id_alokasi' => $idAlokasi]);
    }

    public function test_hapus_jadwal_menghapus_alokasi_non_manual(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 9000 II');
        $idSupir = $this->makeSupir('Supir');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $idJadwal = DB::table('jadwal_shift')->where('id_supir', $idSupir)->value('id_jadwal_shift');
        $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}")->assertStatus(200);

        $this->assertSoftDeleted('alokasi_armada', ['id_supir' => $idSupir, 'tanggal' => '2026-08-10']);
    }
}
