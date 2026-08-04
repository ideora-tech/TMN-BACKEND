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

    private function buatJadwal(string $idProyek, string $idShift, array $idSupir, string $tanggal): void
    {
        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $idProyek,
            'id_shift'  => $idShift,
            'tanggal'   => $tanggal,
            'supir'     => $idSupir,
        ])->assertStatus(200);
    }

    public function test_supir_dengan_armada_penugasan_dapat_alokasi_yang_sama(): void
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
            'sumber'    => 'penugasan',
        ]);
        $this->assertSame($armada->id_armada, DB::table('penugasan')->where('id_penugasan', $penugasan->id_penugasan)->value('id_armada'));
    }

    public function test_supir_tanpa_armada_penugasan_dapat_alokasi_kosong_tanpa_pinjam(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        // Armada nganggur ini TIDAK boleh ikut dipinjamkan — sistem tidak lagi mencari armada kosong.
        $this->makeArmada('B 2000 BB');

        $idShiftSupir = $this->makeSupir('Supir Shift');
        $this->makePenugasan($idShiftSupir, $proyek->id_proyek);
        $idShift = $this->makeShift();

        $this->buatJadwal($proyek->id_proyek, $idShift, [$idShiftSupir], '2026-08-10');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'        => $idShiftSupir,
            'tanggal'         => '2026-08-10',
            'id_armada'       => null,
            'id_pemilik_asal' => null,
            'sumber'          => 'penugasan',
            'keterangan'      => null,
        ]);
    }

    public function test_ganti_armada_supir_dilakukan_lewat_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armadaA = $this->makeArmada('B 5000 EE');
        $armadaB = $this->makeArmada('B 6000 FF');
        $idSupir = $this->makeSupir('Supir Ganti');
        $penugasan = $this->makePenugasan($idSupir, $proyek->id_proyek, $armadaA->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $idSupir, 'tanggal' => '2026-08-10', 'id_armada' => $armadaA->id_armada,
        ]);

        // Ganti armada dilakukan lewat penugasan, bukan lewat alokasi_armada.
        $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", ['id_armada' => $armadaB->id_armada])
            ->assertStatus(200);

        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $idSupir, 'tanggal' => '2026-08-10', 'id_armada' => $armadaB->id_armada, 'dihapus_pada' => null,
        ]);
        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir' => $idSupir, 'tanggal' => '2026-08-10', 'id_armada' => $armadaA->id_armada,
        ]);
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
            'id_armada' => $armada->id_armada, 'sumber' => 'penugasan',
        ]);

        $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", ['id_armada' => null])
            ->assertStatus(200);

        // Mobil dilepas dari penugasan → alokasi ikut jadi kosong, tidak dicarikan gantinya.
        $this->assertDatabaseHas('alokasi_armada', [
            'id_supir'   => $idSupir,
            'tanggal'    => '2026-08-20',
            'id_armada'  => null,
            'dihapus_pada' => null,
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
        $penugasan = $this->makePenugasan($idSupir, $proyek->id_proyek, $armadaA->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $this->putJson("/api/v1/penugasan/{$penugasan->id_penugasan}", ['id_armada' => $armadaB->id_armada])
            ->assertStatus(200);

        // riwayat armada A: satu baris (penugasan) yang sudah digantikan
        $res = $this->getJson("/api/v1/alokasi-armada/riwayat?id_armada={$armadaA->id_armada}");
        $res->assertStatus(200)
            ->assertJsonPath('data.armada.nopol', 'B 9700 PP')
            ->assertJsonCount(1, 'data.items');
        $this->assertNotNull($res->json('data.items.0.dihapus_pada'));

        // riwayat armada B: baris baru yang berlaku
        $resB = $this->getJson("/api/v1/alokasi-armada/riwayat?id_armada={$armadaB->id_armada}");
        $resB->assertStatus(200)->assertJsonCount(1, 'data.items');
        $this->assertSame('penugasan', $resB->json('data.items.0.sumber'));
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

    public function test_hapus_jadwal_menghapus_alokasi(): void
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

    public function test_endpoint_ganti_armada_manual_sudah_dihapus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $armada = $this->makeArmada('B 9900 RR');
        $idSupir = $this->makeSupir('Supir Endpoint Lama');
        $this->makePenugasan($idSupir, $proyek->id_proyek, $armada->id_armada);
        $idShift = $this->makeShift();
        $this->buatJadwal($proyek->id_proyek, $idShift, [$idSupir], '2026-08-10');

        $idAlokasi = DB::table('alokasi_armada')->where('id_supir', $idSupir)->whereNull('dihapus_pada')->value('id_alokasi');

        $this->putJson("/api/v1/alokasi-armada/{$idAlokasi}", ['id_armada' => $armada->id_armada])
            ->assertStatus(404);
        $this->getJson('/api/v1/alokasi-armada/armada-tersedia?tanggal=2026-08-10')
            ->assertStatus(404);
    }
}
