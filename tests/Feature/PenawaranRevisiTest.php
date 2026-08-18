<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PenawaranRevisiTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Revisi Test',
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeRute(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Jakarta - Bandung',
            'asal'          => 'Jakarta',
            'tujuan'        => 'Bandung',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'kode_jenis'         => 'CDD-' . Str::random(4),
            'nama_jenis'         => 'CDD',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    private function makeProyek(string $idKlien, string $tipeHarga = 'per_rit'): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Revisi Test',
            'status'        => 'aktif',
            'tipe_harga'    => $tipeHarga,
        ]);
    }

    private function makePenawaranDisetujui(string $idKlien, string $idProyek, string $tipeHarga = 'per_rit', ?float $nilai = null): string
    {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $idKlien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Induk Revisi',
            'status'          => 'disetujui',
            'tipe_harga'      => $tipeHarga,
            'nilai_penawaran' => $nilai,
            'id_proyek'       => $idProyek,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    private function kirimkan(string $idPenawaran): void
    {
        $this->putJson("/api/v1/penawaran/{$idPenawaran}/status", ['status' => 'terkirim'])
            ->assertStatus(200);
    }

    private function makeProyekRute(string $idProyek, string $idRute, string $idJenis, float $harga, int $ritase = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('proyek_rute')->insert([
            'id_proyek_rute'     => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_proyek'          => $idProyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_penawaran'    => $harga,
            'estimasi_ritase'    => $ritase,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    public function test_store_rute_proyek_ditolak_saat_ada_penawaran_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_penawaran'    => 500000,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Harga terkunci — ubah lewat penawaran revisi');
        $this->assertSame(0, DB::table('proyek_rute')->where('id_proyek', $proyek->id_proyek)->count());
    }

    public function test_update_harga_penawaran_ditolak_saat_ada_penawaran_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 500000);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $res = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}/rute/{$idBaris}", [
            'harga_penawaran' => 900000,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Harga terkunci — ubah lewat penawaran revisi');
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek_rute'  => $idBaris,
            'harga_penawaran' => 500000,
        ]);
    }

    public function test_update_estimasi_ritase_ditolak_saat_ada_penawaran_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 500000, 2);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $res = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}/rute/{$idBaris}", [
            'estimasi_ritase' => 5,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Harga terkunci — ubah lewat penawaran revisi');
    }

    public function test_update_field_ops_tetap_bisa_saat_ada_penawaran_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 500000);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $res = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}/rute/{$idBaris}", [
            'uang_jalan'          => 200000,
            'estimasi_tol'        => 50000,
            'estimasi_bbm'        => 300000,
            'estimasi_biaya_lain' => 25000,
            'keterangan'          => 'Update ops saja',
        ]);

        $res->assertStatus(200);
        $this->assertEquals(200000, $res->json('data.uang_jalan'));
        $this->assertSame('Update ops saja', $res->json('data.keterangan'));
    }

    public function test_update_dengan_nilai_sama_tidak_dianggap_perubahan_harga(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 500000, 2);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $res = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}/rute/{$idBaris}", [
            'harga_penawaran' => 500000,
            'estimasi_ritase' => 2,
            'uang_jalan'      => 150000,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(150000, $res->json('data.uang_jalan'));
    }

    public function test_proyek_manual_tanpa_penawaran_harga_bebas_diedit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/rute", [
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_penawaran'    => 500000,
        ]);
        $res->assertStatus(201);
        $idBaris = $res->json('data.id_proyek_rute');

        $update = $this->putJson("/api/v1/proyek/{$proyek->id_proyek}/rute/{$idBaris}", [
            'harga_penawaran' => 750000,
        ]);
        $update->assertStatus(200)->assertJsonPath('data.harga_penawaran', 750000);
    }

    public function test_buat_penawaran_revisi_berhasil_dengan_induk_benar(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idInduk = $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $this->assertSame($idInduk, $res->json('data.id_penawaran_induk'));
        $this->assertSame('draft', $res->json('data.status'));
        $this->assertMatchesRegularExpression('/^PNW-\d{6}-\d{4}$/', $res->json('data.nomor_penawaran'));
        $this->assertEquals(1200000, $res->json('data.nilai_penawaran'));
        $this->assertSame($proyek->id_proyek, $res->json('data.id_proyek'));
    }

    public function test_buat_penawaran_revisi_judul_default_revisi_nomor_induk(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien      = $this->makeKlien();
        $proyek     = $this->makeProyek($klien);
        $idInduk    = $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $nomorInduk = DB::table('penawaran')->where('id_penawaran', $idInduk)->value('nomor_penawaran');
        $idRute     = $this->makeRute();
        $idJenis    = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 500000, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.judul', "Revisi {$nomorInduk}");
    }

    public function test_buat_penawaran_revisi_judul_kustom_dipakai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'judul' => 'Revisi Harga BBM Naik',
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 500000, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.judul', 'Revisi Harga BBM Naik');
    }

    public function test_buat_penawaran_revisi_tanpa_penawaran_disetujui_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Proyek belum punya penawaran disetujui');
        $this->assertSame(0, DB::table('penawaran')->where('id_proyek', $proyek->id_proyek)->count());
    }

    public function test_buat_penawaran_revisi_per_rit_tanpa_items_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [],
        ]);

        $res->assertStatus(422);
    }

    public function test_buat_penawaran_revisi_per_rit_item_tanpa_harga_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Harga satuan wajib diisi untuk penawaran per rit');
        $this->assertSame(0, DB::table('penawaran')->where('id_proyek', $proyek->id_proyek)->whereNotNull('id_penawaran_induk')->count());
    }

    public function test_buat_penawaran_revisi_borongan_butuh_nilai_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien, 'borongan');
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek, 'borongan', 40000000);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [],
        ]);

        $res->assertStatus(422);
    }

    public function test_buat_penawaran_revisi_borongan_berhasil_tanpa_items(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien, 'borongan');
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek, 'borongan', 40000000);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'nilai_penawaran' => 45000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.tipe_harga', 'borongan')
            ->assertJsonPath('data.nilai_penawaran', 45000000);
    }

    public function test_buat_penawaran_revisi_proyek_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        $proyekLain = ProyekModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-LAIN-REVISI',
            'nama_proyek'   => 'Proyek Perusahaan Lain',
        ]);

        $res = $this->postJson("/api/v1/proyek/{$proyekLain->id_proyek}/penawaran-revisi", [
            'items' => [],
        ]);

        $res->assertStatus(404);
    }

    public function test_revisi_disetujui_menulis_balik_harga_baris_cocok_dan_baris_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $idRuteLama  = $this->makeRute();
        $idJenisLama = $this->makeJenisKendaraan();
        $idBarisLama = $this->makeProyekRute($proyek->id_proyek, $idRuteLama, $idJenisLama, 500000, 1);

        $idRuteBaru  = $this->makeRute();
        $idJenisBaru = $this->makeJenisKendaraan();

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRuteLama, 'id_jenis_kendaraan' => $idJenisLama, 'harga_satuan' => 800000, 'estimasi_ritase' => 3],
                ['id_rute' => $idRuteBaru, 'id_jenis_kendaraan' => $idJenisBaru, 'harga_satuan' => 650000, 'estimasi_ritase' => 2],
            ],
        ]);
        $revisi->assertStatus(201);
        $idRevisi    = $revisi->json('data.id_penawaran');
        $nilaiRevisi = $revisi->json('data.nilai_penawaran');

        $this->kirimkan($idRevisi);
        $res = $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'disetujui']);
        $res->assertStatus(200);

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek_rute'  => $idBarisLama,
            'harga_penawaran' => 800000,
            'estimasi_ritase' => 3,
        ]);
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'          => $proyek->id_proyek,
            'id_rute'            => $idRuteBaru,
            'id_jenis_kendaraan' => $idJenisBaru,
            'harga_penawaran'    => 650000,
            'estimasi_ritase'    => 2,
        ]);
        $this->assertSame(2, DB::table('proyek_rute')->where('id_proyek', $proyek->id_proyek)->whereNull('dihapus_pada')->count());
        $this->assertEquals($nilaiRevisi, (float) DB::table('proyek')->where('id_proyek', $proyek->id_proyek)->value('harga_penawaran'));
    }

    public function test_update_status_mengembalikan_items_tanpa_perlu_refetch(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 700000, 'estimasi_ritase' => 4],
            ],
        ]);
        $idRevisi = $revisi->json('data.id_penawaran');

        $res = $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'terkirim']);
        $res->assertStatus(200);

        $items = $res->json('data.items');
        $this->assertNotNull($items, 'Respons updateStatus wajib menyertakan items tanpa perlu GET ulang');
        $this->assertCount(1, $items);
        $this->assertSame($idRute, $items[0]['id_rute']);
    }

    public function test_revisi_disetujui_menghapus_baris_yang_tidak_disertakan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $idRuteDipertahankan  = $this->makeRute();
        $idJenisDipertahankan = $this->makeJenisKendaraan();
        $idBarisDipertahankan = $this->makeProyekRute($proyek->id_proyek, $idRuteDipertahankan, $idJenisDipertahankan, 100000000, 10);

        $idRuteDihapus  = $this->makeRute();
        $idJenisDihapus = $this->makeJenisKendaraan();
        $idBarisDihapus = $this->makeProyekRute($proyek->id_proyek, $idRuteDihapus, $idJenisDihapus, 10000000, 30);

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRuteDipertahankan, 'id_jenis_kendaraan' => $idJenisDipertahankan, 'harga_satuan' => 100000000, 'estimasi_ritase' => 10],
            ],
        ]);
        $idRevisi    = $revisi->json('data.id_penawaran');
        $nilaiRevisi = $revisi->json('data.nilai_penawaran');
        $this->assertEquals(1000000000, $nilaiRevisi);

        $this->kirimkan($idRevisi);
        $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'disetujui'])->assertStatus(200);

        $this->assertDatabaseHas('proyek_rute', ['id_proyek_rute' => $idBarisDipertahankan, 'dihapus_pada' => null]);
        $this->assertSoftDeleted('proyek_rute', ['id_proyek_rute' => $idBarisDihapus]);

        $sisaBaris = DB::table('proyek_rute')->where('id_proyek', $proyek->id_proyek)->whereNull('dihapus_pada')->get();
        $this->assertCount(1, $sisaBaris);
        $totalBaris = (float) $sisaBaris->sum(fn ($b) => (float) $b->harga_penawaran * (int) $b->estimasi_ritase);
        $hargaProyek = (float) DB::table('proyek')->where('id_proyek', $proyek->id_proyek)->value('harga_penawaran');
        $this->assertEquals($hargaProyek, $totalBaris,
            'Nilai proyek harus selalu sama dengan jumlah subtotal (harga x ritase) baris rate card yang aktif setelah revisi disetujui');
    }

    public function test_revisi_ditolak_tidak_menulis_apa_pun(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 500000, 1);

        $hargaProyekAwal = DB::table('proyek')->where('id_proyek', $proyek->id_proyek)->value('harga_penawaran');

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 999999, 'estimasi_ritase' => 9],
            ],
        ]);
        $idRevisi = $revisi->json('data.id_penawaran');

        $this->kirimkan($idRevisi);
        $res = $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'ditolak']);
        $res->assertStatus(200);

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek_rute'  => $idBaris,
            'harga_penawaran' => 500000,
        ]);
        $this->assertEquals($hargaProyekAwal, DB::table('proyek')->where('id_proyek', $proyek->id_proyek)->value('harga_penawaran'));
    }

    public function test_revisi_borongan_disetujui_hanya_update_harga_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien, 'borongan');
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek, 'borongan', 40000000);

        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 0, 1);

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'nilai_penawaran' => 55000000,
        ]);
        $revisi->assertStatus(201);
        $idRevisi = $revisi->json('data.id_penawaran');

        $this->kirimkan($idRevisi);
        $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'disetujui'])
            ->assertStatus(200);

        $this->assertEquals(55000000, (float) DB::table('proyek')->where('id_proyek', $proyek->id_proyek)->value('harga_penawaran'));
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek_rute'  => $idBaris,
            'harga_penawaran' => 0,
        ]);
    }

    public function test_penawaran_revisi_tidak_bisa_dijadikan_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
            ],
        ]);
        $idRevisi = $revisi->json('data.id_penawaran');
        $this->kirimkan($idRevisi);
        $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'disetujui'])->assertStatus(200);

        $res = $this->postJson('/api/v1/proyek', [
            'id_klien'     => $klien,
            'nama_proyek'  => 'Proyek Dari Revisi Seharusnya Gagal',
            'id_penawaran' => $idRevisi,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Penawaran sudah memiliki proyek');
    }

    public function test_update_status_penawaran_milik_perusahaan_lain_ditolak_404_rate_card_tidak_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $idKlienLain = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $idKlienLain,
            'id_perusahaan' => $idPerusahaanLain,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Lain',
            'dibuat_pada'   => now(),
        ]);
        $proyekLain = ProyekModel::create([
            'id_perusahaan'   => $idPerusahaanLain,
            'id_klien'        => $idKlienLain,
            'kode_proyek'     => 'PRJ-LAIN-' . Str::random(6),
            'nama_proyek'     => 'Proyek Perusahaan Lain',
            'status'          => 'aktif',
            'tipe_harga'      => 'per_rit',
            'harga_penawaran' => 500000,
        ]);

        $idRuteLain = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $idRuteLain,
            'id_perusahaan' => $idPerusahaanLain,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Rute Lain',
            'asal'          => 'A',
            'tujuan'        => 'B',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        $idJenisLain = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $idJenisLain,
            'id_perusahaan'      => $idPerusahaanLain,
            'kode_jenis'         => 'CDD-' . Str::random(4),
            'nama_jenis'         => 'CDD',
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        $idBarisLain = (string) Str::uuid();
        DB::table('proyek_rute')->insert([
            'id_proyek_rute'     => $idBarisLain,
            'id_perusahaan'      => $idPerusahaanLain,
            'id_proyek'          => $proyekLain->id_proyek,
            'id_rute'            => $idRuteLain,
            'id_jenis_kendaraan' => $idJenisLain,
            'harga_penawaran'    => 500000,
            'estimasi_ritase'    => 1,
            'dibuat_pada'        => now(),
        ]);

        $idIndukLain = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $idIndukLain,
            'id_perusahaan'   => $idPerusahaanLain,
            'id_klien'        => $idKlienLain,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Induk Lain',
            'status'          => 'disetujui',
            'tipe_harga'      => 'per_rit',
            'nilai_penawaran' => 500000,
            'id_proyek'       => $proyekLain->id_proyek,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        $idRevisiLain = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'       => $idRevisiLain,
            'id_perusahaan'      => $idPerusahaanLain,
            'id_klien'           => $idKlienLain,
            'nomor_penawaran'    => 'PNW-' . Str::random(8),
            'judul'              => 'Revisi Lain',
            'status'             => 'terkirim',
            'tipe_harga'         => 'per_rit',
            'nilai_penawaran'    => 900000,
            'id_proyek'          => $proyekLain->id_proyek,
            'id_penawaran_induk' => $idIndukLain,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item'  => (string) Str::uuid(),
            'id_perusahaan'      => $idPerusahaanLain,
            'id_penawaran'       => $idRevisiLain,
            'id_rute'            => $idRuteLain,
            'id_jenis_kendaraan' => $idJenisLain,
            'harga_satuan'       => 900000,
            'estimasi_ritase'    => 1,
            'subtotal'           => 900000,
            'dibuat_pada'        => now(),
        ]);

        $res = $this->putJson("/api/v1/penawaran/{$idRevisiLain}/status", ['status' => 'disetujui']);

        $res->assertStatus(404)
            ->assertJsonPath('message', 'Penawaran tidak ditemukan');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek_rute'  => $idBarisLain,
            'harga_penawaran' => 500000,
        ]);
        $this->assertEquals(500000, (float) DB::table('proyek')->where('id_proyek', $proyekLain->id_proyek)->value('harga_penawaran'));
        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idRevisiLain,
            'status'       => 'terkirim',
        ]);
    }

    public function test_buat_penawaran_revisi_kedua_saat_revisi_pertama_belum_selesai_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $revisi1 = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
            ],
        ]);
        $revisi1->assertStatus(201);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 700000, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Masih ada penawaran revisi yang belum selesai');
        $this->assertSame(1, DB::table('penawaran')->where('id_proyek', $proyek->id_proyek)->whereNotNull('id_penawaran_induk')->count());
    }

    public function test_buat_penawaran_revisi_setelah_revisi_sebelumnya_disetujui_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $revisi1 = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
            ],
        ]);
        $idRevisi1 = $revisi1->json('data.id_penawaran');
        $this->kirimkan($idRevisi1);
        $this->putJson("/api/v1/penawaran/{$idRevisi1}/status", ['status' => 'disetujui'])->assertStatus(200);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 700000, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(201);
    }

    public function test_buat_penawaran_revisi_setelah_revisi_sebelumnya_ditolak_boleh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $revisi1 = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
            ],
        ]);
        $idRevisi1 = $revisi1->json('data.id_penawaran');
        $this->kirimkan($idRevisi1);
        $this->putJson("/api/v1/penawaran/{$idRevisi1}/status", ['status' => 'ditolak'])->assertStatus(200);

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 700000, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(201);
    }

    public function test_buat_penawaran_revisi_dengan_item_duplikat_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 700000, 'estimasi_ritase' => 2],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Terdapat rute duplikat dalam item revisi');
        $this->assertSame(0, DB::table('penawaran')->where('id_proyek', $proyek->id_proyek)->whereNotNull('id_penawaran_induk')->count());
    }

    public function test_buat_penawaran_revisi_rute_sama_jenis_kendaraan_beda_bukan_duplikat(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $res = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => null, 'harga_satuan' => 500000, 'estimasi_ritase' => 1],
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
            ],
        ]);

        $res->assertStatus(201);
    }

    public function test_revisi_kedua_setelah_revisi_pertama_disetujui_induk_tetap_penawaran_pertama(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $proyek  = $this->makeProyek($klien);
        $idInduk = $this->makePenawaranDisetujui($klien, $proyek->id_proyek);
        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();

        $revisi1 = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 600000, 'estimasi_ritase' => 1],
            ],
        ]);
        $idRevisi1 = $revisi1->json('data.id_penawaran');
        $this->assertSame($idInduk, $revisi1->json('data.id_penawaran_induk'));

        $this->kirimkan($idRevisi1);
        $this->putJson("/api/v1/penawaran/{$idRevisi1}/status", ['status' => 'disetujui'])->assertStatus(200);

        $revisi2 = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 800000, 'estimasi_ritase' => 1],
            ],
        ]);

        $revisi2->assertStatus(201);
        $this->assertSame($idInduk, $revisi2->json('data.id_penawaran_induk'));
        $this->assertNotSame($idRevisi1, $revisi2->json('data.id_penawaran_induk'));
    }

    public function test_race_status_revisi_diubah_via_db_sebelum_update_status_ditolak_konsisten(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien);
        $this->makePenawaranDisetujui($klien, $proyek->id_proyek);

        $idRute  = $this->makeRute();
        $idJenis = $this->makeJenisKendaraan();
        $idBaris = $this->makeProyekRute($proyek->id_proyek, $idRute, $idJenis, 500000, 1);

        $revisi = $this->postJson("/api/v1/proyek/{$proyek->id_proyek}/penawaran-revisi", [
            'items' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_satuan' => 700000, 'estimasi_ritase' => 2],
            ],
        ]);
        $idRevisi = $revisi->json('data.id_penawaran');
        $this->kirimkan($idRevisi);

        DB::table('penawaran')->where('id_penawaran', $idRevisi)->update(['status' => 'ditolak']);

        $res = $this->putJson("/api/v1/penawaran/{$idRevisi}/status", ['status' => 'disetujui']);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Transisi status tidak valid');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek_rute'  => $idBaris,
            'harga_penawaran' => 500000,
        ]);
        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idRevisi,
            'status'       => 'ditolak',
        ]);
    }
}
