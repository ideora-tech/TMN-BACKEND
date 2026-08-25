<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProyekTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => 'Klien Test',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makeProyek(string $idKlien, string $kodeProyek): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'kode_proyek'   => $kodeProyek,
            'nama_proyek'   => 'Proyek Existing',
        ]);
    }

    public function test_store_proyek_kode_dari_request_diabaikan_pakai_kode_otomatis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, '123');

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => '123',
            'nama_proyek' => 'Proyek Baru',
        ]);

        $res->assertStatus(201);
        $this->assertNotSame('123', $res->json('data.kode_proyek'));
        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-\d{4}$/', $res->json('data.kode_proyek'));
    }

    public function test_store_proyek_tanpa_kode_proyek_input_tetap_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'nama_proyek' => 'Proyek Tanpa Kode',
        ]);

        $res->assertStatus(201);
        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-\d{4}$/', $res->json('data.kode_proyek'));
    }

    public function test_store_proyek_kode_otomatis_bertambah_urut(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $pertama = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'nama_proyek' => 'Proyek Urut Satu',
        ])->json('data.kode_proyek');

        $kedua = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'nama_proyek' => 'Proyek Urut Dua',
        ])->json('data.kode_proyek');

        $this->assertNotSame($pertama, $kedua);
    }

    public function test_menolak_kode_proyek_duplikat_saat_update(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();
        $this->makeProyek($klien->id_klien, 'KODE-A');
        $proyekB = $this->makeProyek($klien->id_klien, 'KODE-B');

        $res = $this->putJson("/api/proyek/{$proyekB->id_proyek}", [
            'kode_proyek' => 'KODE-A',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['kode_proyek']);
    }

    public function test_update_proyek_dengan_kode_sendiri_tidak_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();
        $proyek = $this->makeProyek($klien->id_klien, 'KODE-SENDIRI');

        $res = $this->putJson("/api/proyek/{$proyek->id_proyek}", [
            'kode_proyek' => 'KODE-SENDIRI',
            'nama_proyek' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.nama_proyek', 'Nama Diperbarui');
    }

    private function makePenawaranDisetujui(string $idKlien): string
    {
        return $this->makePenawaranDenganStatus($idKlien, 'disetujui');
    }

    private function makePenawaranDenganStatus(
        string $idKlien,
        string $status,
        ?string $idProyek = null,
        string $tipeHarga = 'per_rit',
        ?float $nilaiPenawaran = null
    ): string {
        $id = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $id,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $idKlien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Test Link Proyek',
            'status'          => $status,
            'tipe_harga'      => $tipeHarga,
            'nilai_penawaran' => $nilaiPenawaran,
            'id_proyek'       => $idProyek,
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        return $id;
    }

    /**
     * Bug nyata: tombol "Buat Proyek" di halaman penawaran mengirim id_penawaran,
     * tapi backend tidak pernah menulis balik id_proyek ke penawaran — akibatnya
     * fitur estimasi otomatis (yang membaca penawaran.id_proyek) tidak pernah dapat data.
     */
    public function test_store_proyek_dengan_id_penawaran_menautkan_balik_ke_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();
        $idPenawaran = $this->makePenawaranDisetujui($klien->id_klien);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-DARI-PNW',
            'nama_proyek'  => 'Proyek Dari Penawaran',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idPenawaran,
            'id_proyek'    => $idProyek,
        ]);
    }

    public function test_store_proyek_tanpa_id_penawaran_tetap_berhasil_seperti_biasa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'nama_proyek' => 'Proyek Manual',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama_proyek', 'Proyek Manual');
        $this->assertMatchesRegularExpression('/^PRJ-\d{4}-\d{4}$/', $res->json('data.kode_proyek'));
    }

    public function test_store_proyek_dengan_id_penawaran_tidak_ada_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-PNW-NGAWUR',
            'nama_proyek'  => 'Proyek Test',
            'id_penawaran' => (string) Str::uuid(),
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_penawaran']);
    }

    private function makeRuteUntukProyekTest(): string
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_rute'     => 'RT-' . Str::random(6),
            'nama_rute'     => 'Bandung - Cirebon',
            'asal'          => 'Bandung',
            'tujuan'        => 'Cirebon',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraanUntukProyekTest(): string
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

    public function test_store_proyek_dari_penawaran_menyalin_item_ke_proyek_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $idPenawaran = DB::table('penawaran')->insertGetId([
            'id_penawaran'    => (string) Str::uuid(),
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $klien->id_klien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Auto Copy Test',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ], 'id_penawaran');
        // insertGetId tidak cocok untuk PK string — ambil ulang id yang benar-benar dipakai:
        $idPenawaran = DB::table('penawaran')->where('nomor_penawaran', 'like', 'PNW-%')
            ->where('judul', 'Penawaran Auto Copy Test')->value('id_penawaran');

        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_penawaran'      => $idPenawaran,
            'id_rute'           => $idRute,
            'id_jenis_kendaraan'=> $idJenis,
            'harga_satuan'      => 500000,
            'estimasi_ritase'   => 1,
            'subtotal'          => 500000,
            'dibuat_pada'       => now(),
        ]);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-AUTOCOPY',
            'nama_proyek'  => 'Proyek Auto Copy Test',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'          => $idProyek,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
        ]);
        $this->assertSame(1, DB::table('proyek_rute')->where('id_proyek', $idProyek)->count());
    }

    public function test_store_proyek_tanpa_id_penawaran_tidak_membuat_proyek_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-NOCOPY',
            'nama_proyek' => 'Proyek Tanpa Penawaran',
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertSame(0, DB::table('proyek_rute')->where('id_proyek', $idProyek)->count());
    }

    public function test_store_proyek_dengan_id_penawaran_milik_perusahaan_lain_ditolak_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);

        $idPenawaranLain = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $idPenawaranLain,
            'id_perusahaan'   => $idPerusahaanLain,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Perusahaan Lain',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'nama_proyek'  => 'Proyek Test Tenant Lain',
            'id_penawaran' => $idPenawaranLain,
        ]);

        $res->assertStatus(404)
            ->assertJsonPath('message', 'Penawaran tidak ditemukan');

        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idPenawaranLain,
            'id_proyek'    => null,
        ]);
        $this->assertSame(0, DB::table('proyek')->where('id_klien', $klien->id_klien)->count());
    }

    public function test_jadikan_proyek_dari_penawaran_belum_disetujui_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien       = $this->makeKlien();
        $idPenawaran = $this->makePenawaranDenganStatus($klien->id_klien, 'terkirim');

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'nama_proyek'  => 'Proyek Dari Penawaran Belum Disetujui',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Penawaran belum disetujui — tidak bisa dijadikan proyek');
        $this->assertSame(0, DB::table('proyek')->where('id_klien', $klien->id_klien)->count());
    }

    public function test_jadikan_proyek_dari_penawaran_sudah_memiliki_proyek_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien         = $this->makeKlien();
        $proyekTerkait = $this->makeProyek($klien->id_klien, 'PRJ-SUDAH-ADA');
        $idPenawaran   = $this->makePenawaranDenganStatus($klien->id_klien, 'disetujui', $proyekTerkait->id_proyek);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'nama_proyek'  => 'Proyek Dari Penawaran Sudah Ber-Proyek',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Penawaran sudah memiliki proyek');
        $this->assertSame(1, DB::table('proyek')->where('id_klien', $klien->id_klien)->count());
    }

    public function test_race_jadikan_proyek_id_proyek_tertaut_setelah_disetujui_ditolak_422_tanpa_dobel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien       = $this->makeKlien();
        $idPenawaran = $this->makePenawaranDenganStatus($klien->id_klien, 'disetujui');

        $proyekDuluan = $this->makeProyek($klien->id_klien, 'PRJ-RACE-DULUAN');
        DB::table('penawaran')->where('id_penawaran', $idPenawaran)
            ->update(['id_proyek' => $proyekDuluan->id_proyek]);

        $jumlahProyekSebelum     = DB::table('proyek')->count();
        $jumlahProyekRuteSebelum = DB::table('proyek_rute')->count();

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'nama_proyek'  => 'Proyek Race Dobel',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('message', 'Penawaran sudah memiliki proyek');

        $this->assertSame($jumlahProyekSebelum, DB::table('proyek')->count());
        $this->assertSame($jumlahProyekRuteSebelum, DB::table('proyek_rute')->count());
        $this->assertDatabaseHas('penawaran', [
            'id_penawaran' => $idPenawaran,
            'id_proyek'    => $proyekDuluan->id_proyek,
        ]);
    }

    public function test_jadikan_proyek_status_aktif_dan_tipe_harga_ikut_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien       = $this->makeKlien();
        $idPenawaran = $this->makePenawaranDenganStatus($klien->id_klien, 'disetujui', null, 'borongan', 45000000);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'nama_proyek'  => 'Proyek Jadikan Dari Penawaran',
            'id_penawaran' => $idPenawaran,
            'status'       => 'draft',
            'tipe_harga'   => 'per_rit',
            'harga_penawaran' => 999,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.tipe_harga', 'borongan')
            ->assertJsonPath('data.harga_penawaran', 45000000);
    }

    public function test_jadikan_proyek_tanpa_id_klien_di_payload_id_klien_ikut_penawaran(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien       = $this->makeKlien();
        $idPenawaran = $this->makePenawaranDisetujui($klien->id_klien);

        $res = $this->postJson('/api/proyek', [
            'nama_proyek'  => 'Proyek Dari Penawaran Tanpa id_klien',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.id_klien', $klien->id_klien);
    }

    public function test_store_proyek_manual_tanpa_id_penawaran_dan_tanpa_id_klien_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $res = $this->postJson('/api/proyek', [
            'nama_proyek' => 'Proyek Tanpa Klien',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_klien']);
    }

    public function test_jadikan_proyek_menyalin_keterangan_item_ke_proyek_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $idPenawaran = $this->makePenawaranDenganStatus($klien->id_klien, 'disetujui');
        DB::table('penawaran_item')->insert([
            'id_penawaran_item'  => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_penawaran'       => $idPenawaran,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_satuan'       => 500000,
            'estimasi_ritase'    => 1,
            'subtotal'           => 500000,
            'keterangan'         => 'Catatan item penawaran',
            'dibuat_pada'        => now(),
        ]);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'nama_proyek'  => 'Proyek Salin Keterangan',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'   => $res->json('data.id_proyek'),
            'id_rute'     => $idRute,
            'keterangan'  => 'Catatan item penawaran',
        ]);
    }

    public function test_store_proyek_dengan_rute_manual_membuat_baris_proyek_rute(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RUTE-MANUAL',
            'nama_proyek' => 'Proyek Rute Manual',
            'rute' => [
                ['id_rute' => $idRute, 'id_jenis_kendaraan' => $idJenis, 'harga_penawaran' => 600000],
            ],
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'       => $idProyek,
            'id_rute'         => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_penawaran' => 600000,
        ]);
    }

    public function test_store_proyek_dengan_rute_invalid_gagal_total_tidak_ada_yang_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'nama_proyek' => 'Proyek Rute Invalid',
            'rute' => [
                ['id_rute' => $this->makeRuteUntukProyekTest(), 'id_jenis_kendaraan' => $this->makeJenisKendaraanUntukProyekTest()],
                ['id_rute' => (string) Str::uuid(), 'id_jenis_kendaraan' => $this->makeJenisKendaraanUntukProyekTest()],
            ],
        ]);

        $res->assertStatus(404);
        $this->assertDatabaseMissing('proyek', ['nama_proyek' => 'Proyek Rute Invalid']);
        $this->assertSame(0, DB::table('proyek_rute')->count());
    }

    public function test_store_proyek_dari_penawaran_menyalin_harga_penawaran_dari_harga_satuan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $idPenawaran,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $klien->id_klien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Harga Penawaran Test',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item' => (string) Str::uuid(),
            'id_perusahaan'     => self::PERUSAHAAN_ID,
            'id_penawaran'      => $idPenawaran,
            'id_rute'           => $idRute,
            'id_jenis_kendaraan'=> $idJenis,
            'harga_satuan'      => 725000,
            'estimasi_ritase'   => 1,
            'subtotal'          => 725000,
            'dibuat_pada'       => now(),
        ]);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-HARGA-PNW',
            'nama_proyek'  => 'Proyek Harga Dari Penawaran',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'        => $idProyek,
            'id_rute'          => $idRute,
            'harga_penawaran'  => 725000,
        ]);
    }

    public function test_store_proyek_dari_penawaran_menyalin_estimasi_ritase(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $idPenawaran = (string) Str::uuid();
        DB::table('penawaran')->insert([
            'id_penawaran'    => $idPenawaran,
            'id_perusahaan'   => self::PERUSAHAAN_ID,
            'id_klien'        => $klien->id_klien,
            'nomor_penawaran' => 'PNW-' . Str::random(8),
            'judul'           => 'Penawaran Ritase Copy Test',
            'status'          => 'disetujui',
            'aktif'           => 1,
            'dibuat_pada'     => now(),
        ]);
        DB::table('penawaran_item')->insert([
            'id_penawaran_item'  => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'id_penawaran'       => $idPenawaran,
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => $idJenis,
            'harga_satuan'       => 500000,
            'estimasi_ritase'    => 4,
            'subtotal'           => 2000000,
            'dibuat_pada'        => now(),
        ]);

        $res = $this->postJson('/api/proyek', [
            'id_klien'     => $klien->id_klien,
            'kode_proyek'  => 'PRJ-RITASE-COPY',
            'nama_proyek'  => 'Proyek Ritase Copy',
            'id_penawaran' => $idPenawaran,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'       => $res->json('data.id_proyek'),
            'id_rute'         => $idRute,
            'estimasi_ritase' => 4,
        ]);
    }

    public function test_store_proyek_manual_dengan_ritase_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RITASE-MANUAL',
            'nama_proyek' => 'Proyek Ritase Manual',
            'rute' => [[
                'id_rute'            => $idRute,
                'id_jenis_kendaraan' => $idJenis,
                'harga_penawaran'    => 750000,
                'estimasi_ritase'    => 3,
            ]],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'       => $res->json('data.id_proyek'),
            'estimasi_ritase' => 3,
        ]);
    }

    public function test_store_proyek_manual_tanpa_ritase_default_1(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RITASE-DEFAULT',
            'nama_proyek' => 'Proyek Ritase Default',
            'rute' => [[
                'id_rute'            => $idRute,
                'id_jenis_kendaraan' => $idJenis,
                'harga_penawaran'    => 500000,
            ]],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'       => $res->json('data.id_proyek'),
            'estimasi_ritase' => 1,
        ]);
    }

    public function test_store_proyek_ritase_nol_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien   = $this->makeKlien();
        $idRute  = $this->makeRuteUntukProyekTest();
        $idJenis = $this->makeJenisKendaraanUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RITASE-NOL',
            'nama_proyek' => 'Proyek Ritase Nol',
            'rute' => [[
                'id_rute'            => $idRute,
                'id_jenis_kendaraan' => $idJenis,
                'estimasi_ritase'    => 0,
            ]],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['rute.0.estimasi_ritase']);
    }

    public function test_rute_proyek_mengembalikan_subtotal_dan_bisa_update_ritase(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien    = $this->makeKlien();
        $idRuteA  = $this->makeRuteUntukProyekTest();
        $idRuteB  = $this->makeRuteUntukProyekTest();
        $idJenis  = $this->makeJenisKendaraanUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-SUBTOTAL',
            'nama_proyek' => 'Proyek Subtotal',
            'rute' => [
                [
                    'id_rute'            => $idRuteA,
                    'id_jenis_kendaraan' => $idJenis,
                    'harga_penawaran'    => 750000,
                    'estimasi_ritase'    => 3,
                ],
                [
                    'id_rute'            => $idRuteB,
                    'id_jenis_kendaraan' => $idJenis,
                ],
            ],
        ]);
        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $list = $this->getJson("/api/proyek/{$idProyek}/rute");
        $list->assertStatus(200);
        $data = collect($list->json('data'));

        $denganHarga = $data->firstWhere('id_rute', $idRuteA);
        $this->assertSame(3, $denganHarga['estimasi_ritase']);
        $this->assertSame(2250000, (int) $denganHarga['subtotal']);

        $tanpaHarga = $data->firstWhere('id_rute', $idRuteB);
        $this->assertSame(1, $tanpaHarga['estimasi_ritase']);
        $this->assertNull($tanpaHarga['subtotal']);

        $update = $this->putJson("/api/proyek/{$idProyek}/rute/{$denganHarga['id_proyek_rute']}", [
            'estimasi_ritase' => 5,
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.estimasi_ritase', 5);
        $this->assertSame(3750000, (int) $update->json('data.subtotal'));
    }

    public function test_export_pdf_proyek_mengembalikan_200(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien->id_klien, 'PRJ-PDF-1');

        $res = $this->get("/api/proyek/{$proyek->id_proyek}/pdf");

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    public function test_export_pdf_proyek_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyekLain = ProyekModel::create([
            'id_perusahaan' => (string) Str::uuid(),
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-PDF-LAIN',
            'nama_proyek'   => 'Proyek Perusahaan Lain',
        ]);

        $this->get("/api/proyek/{$proyekLain->id_proyek}/pdf")->assertStatus(404);
    }

    public function test_membuat_dan_mengubah_harga_penawaran_dan_harga_proyek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'        => $klien->id_klien,
            'kode_proyek'     => 'PRJ-HARGA',
            'nama_proyek'     => 'Proyek Harga',
            'harga_penawaran' => 15000000,
            'harga_proyek'    => 14000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.harga_penawaran', 15000000)
            ->assertJsonPath('data.harga_proyek', 14000000);

        $idProyek = $res->json('data.id_proyek');

        $ubah = $this->putJson("/api/proyek/{$idProyek}", ['harga_proyek' => 13500000]);
        $ubah->assertStatus(200)
            ->assertJsonPath('data.harga_proyek', 13500000)
            ->assertJsonPath('data.harga_penawaran', 15000000);
    }

    public function test_show_proyek_menyertakan_nama_klien(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $proyek = $this->makeProyek($klien->id_klien, 'PRJ-SHOW');

        $res = $this->getJson("/api/proyek/{$proyek->id_proyek}");

        $res->assertStatus(200)
            ->assertJsonPath('data.nama_klien', 'Klien Test');
    }

    public function test_index_dengan_id_klien_perusahaan_lain_tidak_bocor(): void
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

        ProyekModel::create([
            'id_perusahaan' => $idPerusahaanLain,
            'id_klien'      => $idKlienLain,
            'kode_proyek'   => 'PRJ-LAIN',
            'nama_proyek'   => 'Proyek Lain',
        ]);

        $res = $this->getJson("/api/proyek?id_klien={$idKlienLain}");

        $res->assertStatus(200);
        $this->assertSame([], $res->json('data'));
    }

    public function test_store_proyek_tanpa_tipe_harga_default_per_rit(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-TIPE-DEFAULT',
            'nama_proyek' => 'Proyek Tipe Default',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.tipe_harga', 'per_rit');
    }

    public function test_store_proyek_dengan_tipe_harga_borongan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-BORONGAN',
            'nama_proyek' => 'Proyek Borongan',
            'tipe_harga'  => 'borongan',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.tipe_harga', 'borongan');
    }

    public function test_store_proyek_dengan_tipe_harga_tidak_valid_ditolak_422(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien = $this->makeKlien();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-TIPE-INVALID',
            'nama_proyek' => 'Proyek Tipe Invalid',
            'tipe_harga'  => 'cicilan',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['tipe_harga']);
    }

    /**
     * Regresi fix round 1 (reviewer critical): rate card baru mengizinkan
     * id_jenis_kendaraan null ("semua jenis") + 4 kolom operasional baru
     * (uang_jalan, estimasi_tol, estimasi_bbm, estimasi_biaya_lain) — sebelumnya
     * StoreProyekRequest::rules() masih pakai skema lama (id_jenis_kendaraan
     * required_with tanpa nullable → 422; 4 kolom baru tidak ada di rules →
     * dibuang diam-diam oleh validated()).
     */
    public function test_store_proyek_manual_rute_id_jenis_kendaraan_null_dan_kolom_operasional_tersimpan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $idRute = $this->makeRuteUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RUTE-SEMUA-JENIS',
            'nama_proyek' => 'Proyek Rute Semua Jenis',
            'rute' => [[
                'id_rute'             => $idRute,
                'id_jenis_kendaraan'  => null,
                'harga_penawaran'     => 600000,
                'estimasi_ritase'     => 2,
                'uang_jalan'          => 150000,
                'estimasi_tol'        => 50000,
                'estimasi_bbm'        => 80000,
                'estimasi_biaya_lain' => 20000,
            ]],
        ]);

        $res->assertStatus(201);
        $idProyek = $res->json('data.id_proyek');

        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'           => $idProyek,
            'id_rute'             => $idRute,
            'id_jenis_kendaraan'  => null,
            'harga_penawaran'     => 600000,
            'estimasi_ritase'     => 2,
            'uang_jalan'          => 150000,
            'estimasi_tol'        => 50000,
            'estimasi_bbm'        => 80000,
            'estimasi_biaya_lain' => 20000,
        ]);
    }

    public function test_store_proyek_manual_rute_tanpa_id_jenis_kendaraan_sama_sekali_tetap_berhasil(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $klien  = $this->makeKlien();
        $idRute = $this->makeRuteUntukProyekTest();

        $res = $this->postJson('/api/proyek', [
            'id_klien'    => $klien->id_klien,
            'kode_proyek' => 'PRJ-RUTE-TANPA-JENIS-KEY',
            'nama_proyek' => 'Proyek Rute Tanpa Jenis Key',
            'rute' => [[
                'id_rute'         => $idRute,
                'harga_penawaran' => 400000,
            ]],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('proyek_rute', [
            'id_proyek'          => $res->json('data.id_proyek'),
            'id_rute'            => $idRute,
            'id_jenis_kendaraan' => null,
        ]);
    }
}
