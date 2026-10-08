<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParameterTagihanAddDropOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private const KETERANGAN_OTOMATIS = 'Add Drop otomatis dari titik drop penugasan';

    private function makeProyek(string $tipeHarga = 'per_rit', array $tarif = ['biaya_add_drop' => 150000, 'biaya_overnight' => 300000]): string
    {
        $idKlien = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $idKlien, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(8), 'nama_klien' => 'Klien Add Drop', 'dibuat_pada' => now(),
        ]);

        $idProyek = ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Add Drop',
            'tipe_harga'    => $tipeHarga,
        ])->id_proyek;

        DB::table('penawaran')->insert(array_merge([
            'id_penawaran' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID, 'id_klien' => $idKlien,
            'nomor_penawaran' => 'PNW-' . Str::random(6), 'judul' => 'Penawaran', 'status' => 'disetujui',
            'tipe_harga' => $tipeHarga, 'id_proyek' => $idProyek, 'aktif' => 1, 'dibuat_pada' => now(),
        ], $tarif));

        return (string) $idProyek;
    }

    private function makePenugasan(string $idProyek, array $titikDrop): string
    {
        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' AD',
            'merk'          => 'Hino',
        ])->id_armada;

        $idSupir = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $idSupir, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => 'Supir Add Drop', 'no_sim' => 'SIM-' . Str::random(8), 'dibuat_pada' => now(),
        ]);

        $res = $this->postJson('/api/penugasan', [
            'id_proyek'  => $idProyek,
            'id_armada'  => $idArmada,
            'id_supir'   => $idSupir,
            'titik_drop' => $titikDrop,
        ]);
        $res->assertStatus(201);

        return (string) $res->json('data.id_penugasan');
    }

    private function mulaiTrip(string $idPenugasan): string
    {
        $res = $this->postJson('/api/trip/mulai', ['id_penugasan' => $idPenugasan]);
        $res->assertStatus(201);
        return (string) $res->json('data.id_trip');
    }

    private function parameter(string $idTrip): ?object
    {
        return DB::table('parameter_tagihan_trip')->where('id_trip', $idTrip)->first();
    }

    public function test_mulai_trip_mengisi_add_drop_otomatis_dari_titik_drop_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idTrip = $this->mulaiTrip($this->makePenugasan($this->makeProyek(), ['JLB', 'MRY']));

        $p = $this->parameter($idTrip);
        $this->assertNotNull($p);
        $this->assertSame(2, (int) $p->jumlah_add_drop);
        $this->assertEquals(150000, $p->tarif_add_drop);
        $this->assertSame(0, (int) $p->add_drop_manual);
        $this->assertSame(self::KETERANGAN_OTOMATIS, $p->keterangan);

        $this->getJson("/api/trip/{$idTrip}/parameter-tagihan")
            ->assertOk()
            ->assertJsonPath('data.add_drop_manual', false)
            ->assertJsonPath('data.jumlah_titik_drop', 2)
            ->assertJsonPath('data.nilai.jumlah_add_drop', 2)
            ->assertJsonPath('data.rincian.total_parameter', 300000);
    }

    public function test_tidak_mengisi_bila_tanpa_titik_drop_tanpa_tarif_atau_proyek_borongan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $tanpaDrop = $this->mulaiTrip($this->makePenugasan($this->makeProyek(), []));
        $tanpaTarif = $this->mulaiTrip($this->makePenugasan($this->makeProyek('per_rit', ['biaya_overnight' => 300000]), ['JLB']));
        $borongan = $this->mulaiTrip($this->makePenugasan($this->makeProyek('borongan'), ['JLB']));

        $this->assertNull($this->parameter($tanpaDrop));
        $this->assertNull($this->parameter($tanpaTarif));
        $this->assertNull($this->parameter($borongan));
    }

    public function test_ubah_titik_drop_penugasan_saat_trip_berjalan_memperbarui_add_drop(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasan($this->makeProyek(), ['JLB']);
        $idTrip = $this->mulaiTrip($idPenugasan);
        $this->assertSame(1, (int) $this->parameter($idTrip)->jumlah_add_drop);

        $this->putJson("/api/penugasan/{$idPenugasan}", ['titik_drop' => ['JLB', 'MRY', 'CKR']])->assertStatus(200);

        $this->assertSame(3, (int) $this->parameter($idTrip)->jumlah_add_drop);
        $this->assertSame(3, DB::table('titik_drop_trip')->where('id_trip', $idTrip)->whereNull('dihapus_pada')->count());

        $this->putJson("/api/penugasan/{$idPenugasan}", ['titik_drop' => []])->assertStatus(200);

        $p = $this->parameter($idTrip);
        $this->assertSame(0, (int) $p->jumlah_add_drop);
        $this->assertNull($p->tarif_add_drop);
        $this->assertNull($p->keterangan);
    }

    public function test_add_drop_yang_diubah_manual_tidak_ditimpa_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasan($this->makeProyek(), ['JLB', 'MRY']);
        $idTrip = $this->mulaiTrip($idPenugasan);

        $this->putJson("/api/trip/{$idTrip}/parameter-tagihan", [
            'jumlah_overnight' => 0, 'jumlah_add_drop' => 1, 'cross_cluster' => false, 'cancellation' => false,
            'keterangan' => 'Klien hanya setuju 1 drop',
        ])->assertOk()->assertJsonPath('data.add_drop_manual', true);

        $this->putJson("/api/penugasan/{$idPenugasan}", ['titik_drop' => ['JLB', 'MRY', 'CKR']])->assertStatus(200);

        $p = $this->parameter($idTrip);
        $this->assertSame(1, (int) $p->jumlah_add_drop);
        $this->assertSame('Klien hanya setuju 1 drop', $p->keterangan);
    }

    public function test_simpan_parameter_lain_tanpa_mengubah_add_drop_tetap_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasan($this->makeProyek(), ['JLB', 'MRY']);
        $idTrip = $this->mulaiTrip($idPenugasan);

        $this->putJson("/api/trip/{$idTrip}/parameter-tagihan", [
            'jumlah_overnight' => 1, 'jumlah_add_drop' => 2, 'cross_cluster' => false, 'cancellation' => false,
            'keterangan' => self::KETERANGAN_OTOMATIS,
        ])->assertOk()->assertJsonPath('data.add_drop_manual', false);

        $this->putJson("/api/penugasan/{$idPenugasan}", ['titik_drop' => ['JLB', 'MRY', 'CKR']])->assertStatus(200);

        $p = $this->parameter($idTrip);
        $this->assertSame(3, (int) $p->jumlah_add_drop);
        $this->assertSame(1, (int) $p->jumlah_overnight);
    }
}
