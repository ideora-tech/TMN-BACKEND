<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Armada\ArmadaModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripTitikDropTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Titik Drop',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSupir(): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Titik Drop',
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeProyek(): string
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $this->makeKlien(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Titik Drop',
        ])->id_proyek;
    }

    private function makePenugasanDenganDrop(array $titikDrop): string
    {
        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' TD',
            'merk'          => 'Hino',
        ])->id_armada;

        $res = $this->postJson('/api/penugasan', [
            'id_proyek'  => $this->makeProyek(),
            'id_armada'  => $idArmada,
            'id_supir'   => $this->makeSupir(),
            'titik_drop' => $titikDrop,
        ]);
        $res->assertStatus(201);

        return (string) $res->json('data.id_penugasan');
    }

    private function mulaiTripUntukPenugasan(string $idPenugasan): string
    {
        $res = $this->postJson('/api/trip/mulai', ['id_penugasan' => $idPenugasan]);
        $res->assertStatus(201);
        return (string) $res->json('data.id_trip');
    }

    public function test_mulai_trip_menyalin_titik_drop_dari_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasanDenganDrop(['JLB', 'MRY']);

        $idTrip = $this->mulaiTripUntukPenugasan($idPenugasan);

        $lokasi = DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->pluck('lokasi')
            ->all();

        $this->assertSame(['JLB', 'MRY'], $lokasi);
    }

    public function test_mulai_trip_menyalin_uang_jalan_tambahan_titik_drop_dari_penugasan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idArmada = ArmadaModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => 'B ' . rand(1000, 9999) . ' TD',
            'merk'          => 'Hino',
        ])->id_armada;

        $create = $this->postJson('/api/penugasan', [
            'id_proyek'  => $this->makeProyek(),
            'id_armada'  => $idArmada,
            'id_supir'   => $this->makeSupir(),
            'titik_drop' => [
                ['lokasi' => 'JLB', 'uang_jalan_tambahan' => 50000],
                ['lokasi' => 'MRY', 'uang_jalan_tambahan' => 0],
            ],
        ]);
        $create->assertStatus(201)->assertJsonPath('data.titik_drop', ['JLB', 'MRY']);
        $this->assertSame(
            [['lokasi' => 'JLB', 'uang_jalan_tambahan' => 50000], ['lokasi' => 'MRY', 'uang_jalan_tambahan' => 0]],
            $create->json('data.titik_drop_detail'),
        );

        $idTrip = $this->mulaiTripUntukPenugasan((string) $create->json('data.id_penugasan'));

        $rows = DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)->whereNull('dihapus_pada')->orderBy('urutan')
            ->get(['lokasi', 'uang_jalan_tambahan']);

        $this->assertSame('JLB', $rows[0]->lokasi);
        $this->assertEquals(50000.0, (float) $rows[0]->uang_jalan_tambahan);
        $this->assertSame('MRY', $rows[1]->lokasi);
        $this->assertEquals(0.0, (float) $rows[1]->uang_jalan_tambahan);
    }

    public function test_edit_titik_drop_penugasan_tidak_mengubah_trip_yang_sudah_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasanDenganDrop(['JLB']);

        $idTrip = $this->mulaiTripUntukPenugasan($idPenugasan);

        $this->putJson("/api/penugasan/{$idPenugasan}", ['titik_drop' => ['KPM']])
            ->assertStatus(200);

        $lokasi = DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->pluck('lokasi')
            ->all();

        $this->assertSame(['JLB'], $lokasi);
    }

    public function test_put_titik_drop_trip_replace_realisasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasanDenganDrop(['JLB']);
        $idTrip = $this->mulaiTripUntukPenugasan($idPenugasan);

        $idLama = DB::table('titik_drop_trip')->where('id_trip', $idTrip)->whereNull('dihapus_pada')->value('id_titik_drop');
        $this->assertNotNull($idLama);

        $res = $this->putJson("/api/trip/{$idTrip}/titik-drop", [
            'titik_drop' => ['JLB', 'MRY', 'RDS', 'KPM'],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.titik_drop', ['JLB', 'MRY', 'RDS', 'KPM']);

        $this->assertNotNull(DB::table('titik_drop_trip')->where('id_titik_drop', $idLama)->value('dihapus_pada'));

        $lokasi = DB::table('titik_drop_trip')
            ->where('id_trip', $idTrip)
            ->whereNull('dihapus_pada')
            ->orderBy('urutan')
            ->pluck('lokasi')
            ->all();

        $this->assertSame(['JLB', 'MRY', 'RDS', 'KPM'], $lokasi);
    }

    public function test_put_titik_drop_ditolak_bila_sudah_difakturkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPenugasan = $this->makePenugasanDenganDrop(['JLB']);
        $idTrip = $this->mulaiTripUntukPenugasan($idPenugasan);

        $idFaktur = (string) Str::uuid();
        DB::table('faktur')->insert([
            'id_faktur'      => $idFaktur,
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'nomor_faktur'   => 'FK-TEST-' . Str::random(6),
            'total'          => 0,
            'status'         => 'draft',
            'tanggal_faktur' => now()->toDateString(),
            'dibuat_pada'    => now(),
        ]);
        DB::table('faktur_trip')->insert([
            'id_faktur_trip' => (string) Str::uuid(),
            'id_faktur'      => $idFaktur,
            'id_trip'        => $idTrip,
            'dibuat_pada'    => now(),
        ]);

        $res = $this->putJson("/api/trip/{$idTrip}/titik-drop", ['titik_drop' => ['JLB', 'MRY']]);

        $res->assertStatus(422);
    }
}
