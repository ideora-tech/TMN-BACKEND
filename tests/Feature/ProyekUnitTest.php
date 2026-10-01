<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProyekUnitTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $proyek = ProyekModel::create([
            'id_perusahaan' => $idPerusahaan,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Unit Test',
        ]);
        return $proyek->id_proyek;
    }

    private function makeArmada(string $nopol, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('armada')->insert(array_merge([
            'id_armada'     => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nopol'         => $nopol,
            'merk'          => 'Hino',
            'status'        => 'tersedia',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ], $override));
        return $id;
    }

    private function makeSupir(string $nama, ?string $idArmadaDefault, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'          => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'nama'              => $nama,
            'no_sim'            => 'SIM-' . Str::random(6),
            'status'            => $status,
            'id_armada_default' => $idArmadaDefault,
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function makeVendor(): string
    {
        $id = (string) Str::uuid();
        DB::table('vendor')->insert([
            'id_vendor'     => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_vendor'   => 'VDR-' . Str::random(8),
            'nama_vendor'   => 'Vendor Sejahtera',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeKontrak(string $idVendor, string $status = 'aktif'): string
    {
        $id = (string) Str::uuid();
        DB::table('kontrak_vendor')->insert([
            'id_kontrak_vendor' => $id,
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_vendor'         => $idVendor,
            'mekanisme'         => 'unit_driver',
            'status'            => $status,
            'dibuat_pada'       => now(),
        ]);
        return $id;
    }

    private function makeSupirVendor(string $idVendor, string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('supir_vendor')->insert([
            'id_supir_vendor' => $id,
            'id_vendor'       => $idVendor,
            'nama'            => $nama,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function makeArmadaVendor(string $idVendor, string $nopol, ?string $idKontrak, ?string $idSupirVendor = null, array $override = []): string
    {
        $id = (string) Str::uuid();
        DB::table('armada_vendor')->insert(array_merge([
            'id_armada_vendor'        => $id,
            'id_vendor'               => $idVendor,
            'nopol'                   => $nopol,
            'merk'                    => 'Isuzu',
            'aktif'                   => 1,
            'id_kontrak_vendor'       => $idKontrak,
            'id_supir_vendor_default' => $idSupirVendor,
            'dibuat_pada'             => now(),
        ], $override));
        return $id;
    }

    private function makeJenisKendaraan(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'JK-' . Str::random(4),
            'nama_jenis'         => $nama,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    public function test_jenis_unit_vendor_di_opsi_sama_dengan_di_daftar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idVendor = $this->makeVendor();
        $idAv = $this->makeArmadaVendor($idVendor, 'B 2001 VV', $this->makeKontrak($idVendor), null, [
            'id_jenis_kendaraan' => $this->makeJenisKendaraan('CDD Long'),
            'jenis'              => null,
        ]);

        $opsi = collect($this->getJson("/api/proyek/{$idProyek}/unit/opsi")->json('data'))->firstWhere('id_armada_vendor', $idAv);
        $this->assertSame('CDD Long', $opsi['nama_jenis']);

        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'vendor', 'id_armada_vendor' => $idAv]]])->assertStatus(201);
        $daftar = collect($this->getJson("/api/proyek/{$idProyek}/unit")->json('data'))->firstWhere('id_armada_vendor', $idAv);
        $this->assertSame('CDD Long', $daftar['nama_jenis']);
    }

    public function test_opsi_berisi_unit_internal_aktif_dan_unit_vendor_berkontrak_aktif_beserta_pemegang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $idA = $this->makeArmada('B 1001 AA');
        $this->makeSupir('Budi', $idA);
        $idB = $this->makeArmada('B 1002 BB', ['status' => 'tidak_aktif']);

        $idVendor = $this->makeVendor();
        $idKontrak = $this->makeKontrak($idVendor);
        $idSupirVendor = $this->makeSupirVendor($idVendor, 'Joko Vendor');
        $idAv = $this->makeArmadaVendor($idVendor, 'B 2001 VV', $idKontrak, $idSupirVendor);
        $idAvTanpaKontrak = $this->makeArmadaVendor($idVendor, 'B 2002 VV', null);
        $idAvKontrakDraft = $this->makeArmadaVendor($idVendor, 'B 2003 VV', $this->makeKontrak($idVendor, 'draft'));

        $res = $this->getJson("/api/proyek/{$idProyek}/unit/opsi")->assertStatus(200);
        $opsi = collect($res->json('data'));

        $internal = $opsi->firstWhere('id_armada', $idA);
        $this->assertNotNull($internal);
        $this->assertSame('internal', $internal['sumber']);
        $this->assertSame('B 1001 AA', $internal['nopol']);
        $this->assertSame('Budi', $internal['nama_supir']);
        $this->assertNull($opsi->firstWhere('id_armada', $idB));

        $vendor = $opsi->firstWhere('id_armada_vendor', $idAv);
        $this->assertNotNull($vendor);
        $this->assertSame('vendor', $vendor['sumber']);
        $this->assertSame('Joko Vendor', $vendor['nama_supir']);
        $this->assertSame('Vendor Sejahtera', $vendor['nama_vendor']);
        $this->assertNull($opsi->firstWhere('id_armada_vendor', $idAvTanpaKontrak));
        $this->assertNull($opsi->firstWhere('id_armada_vendor', $idAvKontrakDraft));
    }

    public function test_tambah_unit_tidak_membuat_penugasan_maupun_uang_jalan_dan_tampil_di_daftar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idA = $this->makeArmada('B 1001 AA');
        $this->makeSupir('Budi', $idA);
        $idVendor = $this->makeVendor();
        $idAv = $this->makeArmadaVendor($idVendor, 'B 2001 VV', $this->makeKontrak($idVendor), $this->makeSupirVendor($idVendor, 'Joko Vendor'));

        $this->postJson("/api/proyek/{$idProyek}/unit", [
            'unit' => [
                ['sumber' => 'internal', 'id_armada' => $idA],
                ['sumber' => 'vendor', 'id_armada_vendor' => $idAv],
            ],
        ])->assertStatus(201)->assertJsonCount(2, 'data');

        $daftar = collect($this->getJson("/api/proyek/{$idProyek}/unit")->assertStatus(200)->json('data'));
        $this->assertCount(2, $daftar);
        $this->assertSame('Budi', $daftar->firstWhere('id_armada', $idA)['nama_supir']);
        $this->assertSame('Joko Vendor', $daftar->firstWhere('id_armada_vendor', $idAv)['nama_supir']);
        $this->assertSame('Vendor Sejahtera', $daftar->firstWhere('id_armada_vendor', $idAv)['nama_vendor']);

        $this->assertSame(0, DB::table('penugasan')->where('id_proyek', $idProyek)->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_unit_yang_sudah_ada_di_proyek_ditolak_dan_hilang_dari_opsi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idA = $this->makeArmada('B 1001 AA');

        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $idA]]])->assertStatus(201);
        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $idA]]])->assertStatus(409);
        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [
            ['sumber' => 'internal', 'id_armada' => $this->makeArmada('B 1003 CC')],
            ['sumber' => 'internal', 'id_armada' => $idA],
        ]])->assertStatus(409);

        $this->assertSame(1, DB::table('proyek_unit')->where('id_proyek', $idProyek)->whereNull('dihapus_pada')->count());
        $this->assertNull(collect($this->getJson("/api/proyek/{$idProyek}/unit/opsi")->json('data'))->firstWhere('id_armada', $idA));
    }

    public function test_unit_yang_sama_boleh_dipakai_di_proyek_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idA = $this->makeArmada('B 1001 AA');

        foreach ([$this->makeProyek(), $this->makeProyek()] as $idProyek) {
            $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $idA]]])->assertStatus(201);
        }
    }

    public function test_unit_tidak_tersedia_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idVendor = $this->makeVendor();

        $tidakTersedia = [
            ['sumber' => 'internal', 'id_armada' => $this->makeArmada('B 1002 BB', ['status' => 'tidak_aktif'])],
            ['sumber' => 'internal', 'id_armada' => (string) Str::uuid()],
            ['sumber' => 'vendor', 'id_armada_vendor' => $this->makeArmadaVendor($idVendor, 'B 2002 VV', null)],
        ];

        foreach ($tidakTersedia as $unit) {
            $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [$unit]])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('proyek_unit')->count());
    }

    public function test_hapus_unit_soft_delete_lalu_bisa_ditambah_lagi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idA = $this->makeArmada('B 1001 AA');
        $idUnit = $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $idA]]])
            ->json('data.0.id_proyek_unit');

        $this->deleteJson("/api/proyek/{$idProyek}/unit/{$idUnit}")->assertStatus(200);
        $this->getJson("/api/proyek/{$idProyek}/unit")->assertJsonCount(0, 'data');
        $this->assertNotNull(DB::table('proyek_unit')->where('id_proyek_unit', $idUnit)->value('dihapus_pada'));
        $this->deleteJson("/api/proyek/{$idProyek}/unit/{$idUnit}")->assertStatus(404);

        $this->assertNotNull(collect($this->getJson("/api/proyek/{$idProyek}/unit/opsi")->json('data'))->firstWhere('id_armada', $idA));
        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $idA]]])->assertStatus(201);
    }

    private function tambahUnit(string $idProyek, string $nopol): string
    {
        return $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $this->makeArmada($nopol)]]])
            ->assertStatus(201)
            ->json('data.0.id_proyek_unit');
    }

    public function test_hapus_massal_menghapus_semua_unit_terpilih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $id1 = $this->tambahUnit($idProyek, 'B 1001 AA');
        $id2 = $this->tambahUnit($idProyek, 'B 1002 AA');
        $id3 = $this->tambahUnit($idProyek, 'B 1003 AA');

        $this->deleteJson("/api/proyek/{$idProyek}/unit", ['ids' => [$id1, $id3]])
            ->assertStatus(200)
            ->assertJsonPath('data.dihapus', 2);

        $sisa = collect($this->getJson("/api/proyek/{$idProyek}/unit")->json('data'))->pluck('id_proyek_unit')->all();
        $this->assertSame([$id2], $sisa);
        $this->assertNotNull(DB::table('proyek_unit')->where('id_proyek_unit', $id1)->value('dihapus_pada'));
        $this->assertNotNull(DB::table('proyek_unit')->where('id_proyek_unit', $id3)->value('dihapus_pada'));
    }

    public function test_hapus_massal_ditolak_utuh_jika_ada_unit_bukan_milik_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();
        $idProyekLain = $this->makeProyek();
        $idMilik = $this->tambahUnit($idProyek, 'B 1001 AA');
        $idProyekLainUnit = $this->tambahUnit($idProyekLain, 'B 1002 AA');
        $idTerhapus = $this->tambahUnit($idProyek, 'B 1003 AA');
        $this->deleteJson("/api/proyek/{$idProyek}/unit/{$idTerhapus}")->assertStatus(200);

        foreach ([[$idMilik, $idProyekLainUnit], [$idMilik, $idTerhapus], [$idMilik, (string) Str::uuid()]] as $ids) {
            $this->deleteJson("/api/proyek/{$idProyek}/unit", ['ids' => $ids])->assertStatus(404);
        }

        $this->assertNull(DB::table('proyek_unit')->where('id_proyek_unit', $idMilik)->value('dihapus_pada'));
        $this->assertNull(DB::table('proyek_unit')->where('id_proyek_unit', $idProyekLainUnit)->value('dihapus_pada'));
    }

    public function test_hapus_massal_proyek_perusahaan_lain_404_dan_payload_tidak_valid_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idProyekLain = $this->makeProyek($idPerusahaanLain);
        $idUnitLain = (string) Str::uuid();
        DB::table('proyek_unit')->insert([
            'id_proyek_unit' => $idUnitLain, 'id_perusahaan' => $idPerusahaanLain, 'id_proyek' => $idProyekLain,
            'sumber' => 'internal', 'id_armada' => (string) Str::uuid(), 'dibuat_pada' => now(),
        ]);

        $this->deleteJson("/api/proyek/{$idProyekLain}/unit", ['ids' => [$idUnitLain]])->assertStatus(404);
        $this->assertNull(DB::table('proyek_unit')->where('id_proyek_unit', $idUnitLain)->value('dihapus_pada'));

        $idProyek = $this->makeProyek();
        $this->deleteJson("/api/proyek/{$idProyek}/unit", [])->assertStatus(422);
        $this->deleteJson("/api/proyek/{$idProyek}/unit", ['ids' => []])->assertStatus(422);
    }

    public function test_proyek_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $idProyekLain = $this->makeProyek($idPerusahaanLain);
        $idUnitLain = (string) Str::uuid();
        DB::table('proyek_unit')->insert([
            'id_proyek_unit' => $idUnitLain, 'id_perusahaan' => $idPerusahaanLain, 'id_proyek' => $idProyekLain,
            'sumber' => 'internal', 'id_armada' => (string) Str::uuid(), 'dibuat_pada' => now(),
        ]);
        $idA = $this->makeArmada('B 1001 AA');

        $this->getJson("/api/proyek/{$idProyekLain}/unit")->assertStatus(404);
        $this->getJson("/api/proyek/{$idProyekLain}/unit/opsi")->assertStatus(404);
        $this->postJson("/api/proyek/{$idProyekLain}/unit", ['unit' => [['sumber' => 'internal', 'id_armada' => $idA]]])->assertStatus(404);
        $this->deleteJson("/api/proyek/{$idProyekLain}/unit/{$idUnitLain}")->assertStatus(404);

        $idProyekSendiri = $this->makeProyek();
        $this->deleteJson("/api/proyek/{$idProyekSendiri}/unit/{$idUnitLain}")->assertStatus(404);
        $this->assertNull(DB::table('proyek_unit')->where('id_proyek_unit', $idUnitLain)->value('dihapus_pada'));
    }

    public function test_payload_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idProyek = $this->makeProyek();

        $this->postJson("/api/proyek/{$idProyek}/unit", [])->assertStatus(422);
        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'sewa', 'id_armada' => (string) Str::uuid()]]])->assertStatus(422);
        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'internal']]])->assertStatus(422);
        $this->postJson("/api/proyek/{$idProyek}/unit", ['unit' => [['sumber' => 'vendor']]])->assertStatus(422);
    }
}
