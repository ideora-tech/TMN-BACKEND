<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeKaryawan(string $nama, string $nik, float $gaji, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => 1,
            'gaji_pokok' => $gaji, 'dibuat_pada' => now(),
        ], $extra));
        return $id;
    }

    private function buatPeriode(string $bulan = null): string
    {
        $bulan = $bulan ?? now()->format('Y-m');
        $res = $this->postJson('/api/payroll/periode', ['bulan' => $bulan]);
        $res->assertStatus(201);
        return $res->json('data.id_periode');
    }

    private function makeJabatan(float $tunjangan): string
    {
        $id = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'JAB-' . substr($id, 0, 8), 'nama_jabatan' => 'Jabatan Tunjangan',
            'tunjangan_jabatan' => $tunjangan, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeExit(string $idKaryawan, string $tanggalEfektif): void
    {
        DB::table('karyawan_exit')->insert([
            'id_exit' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'jenis_exit' => 'resign',
            'tanggal_efektif' => $tanggalEfektif, 'dibuat_pada' => now(),
        ]);
    }

    private function pengaturanPayload(array $override = []): array
    {
        return array_merge([
            'tanggal_mulai_cutoff'       => 1,
            'hari_kerja_per_bulan'       => 25,
            'persen_bpjs_kesehatan'      => 1,
            'persen_bpjs_jht'            => 2,
            'persen_bpjs_jp'             => 1,
            'plafon_gaji_bpjs_kesehatan' => 12000000,
        ], $override);
    }

    public function test_rentang_periode_mengikuti_cutoff(): void
    {
        $this->actingAsRole('SUPERADMIN');

        // default cutoff 21: bulan Juli = 21 Jun s/d 20 Jul
        $res = $this->getJson('/api/payroll/preview-rentang?bulan=2026-07');
        $res->assertStatus(200)
            ->assertJsonPath('data.tanggal_mulai', '2026-06-21')
            ->assertJsonPath('data.tanggal_selesai', '2026-07-20');

        // cutoff 1 = bulan kalender
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        $res2 = $this->getJson('/api/payroll/preview-rentang?bulan=2026-07');
        $res2->assertStatus(200)
            ->assertJsonPath('data.tanggal_mulai', '2026-07-01')
            ->assertJsonPath('data.tanggal_selesai', '2026-07-31');
    }

    public function test_periode_tumpang_tindih_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->buatPeriode('2026-07');

        $this->postJson('/api/payroll/periode', ['bulan' => '2026-07'])->assertStatus(422);
    }

    public function test_list_periode_bisa_dicari_dan_difilter_status(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());

        $idA = $this->buatPeriode('2026-01');
        $idB = $this->buatPeriode('2026-02');
        $this->makeKaryawan('Filter Test', 'NIK-PAY-FLT', 5000000);
        $this->postJson("/api/payroll/periode/{$idB}/generate")->assertStatus(200);
        $this->postJson("/api/payroll/periode/{$idB}/finalisasi")->assertStatus(200);

        $resSearch = $this->getJson('/api/payroll/periode?search=' . urlencode('Jan 2026'));
        $resSearch->assertStatus(200);
        $this->assertCount(1, $resSearch->json('data'));
        $this->assertSame($idA, $resSearch->json('data.0.id_periode'));

        $resStatus = $this->getJson('/api/payroll/periode?status=final');
        $resStatus->assertStatus(200);
        $this->assertCount(1, $resStatus->json('data'));
        $this->assertSame($idB, $resStatus->json('data.0.id_periode'));
    }

    public function test_generate_slip_menghitung_komponen(): void
    {
        $this->actingAsRole('SUPERADMIN');

        // Pengaturan payroll: cutoff 1 (bulan kalender), 25 hari kerja, BPJS 1%/2%/1%
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        // Gaji 5.000.000, ikut BPJS lengkap, TK/0
        // Neto tahunan = (5jt − 250rb biaya jabatan) × 12 = 57jt; PKP 3jt → PPh21 150rb/thn = 12.500/bln
        $idKaryawan = $this->makeKaryawan('Andi Payroll', 'NIK-PAY-01', 5000000, [
            'ikut_bpjs_kesehatan' => 1,
            'ikut_bpjs_ketenagakerjaan' => 1,
            'status_ptkp' => 'TK/0',
        ]);

        // 2 alpha dalam bulan berjalan → potongan 2 × (5jt/25) = 400.000
        $bulan = now()->format('Y-m');
        DB::table('absensi')->insert([
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-05', 'status' => 'alpha', 'dibuat_pada' => now(),
            ],
            [
                'id_absensi' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
                'id_karyawan' => $idKaryawan, 'tanggal' => $bulan . '-06', 'status' => 'alpha', 'dibuat_pada' => now(),
            ],
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $res = $this->getJson("/api/payroll/periode/{$idPeriode}");
        $res->assertStatus(200);

        $slip = collect($res->json('data.slips'))->firstWhere('id_karyawan', $idKaryawan);
        $this->assertNotNull($slip);
        $this->assertEquals(5000000, $slip['gaji_pokok']);
        $this->assertEquals(2, $slip['jumlah_alpha']);
        $this->assertEquals(400000, $slip['potongan_absen']);
        $this->assertEquals(50000, $slip['potongan_bpjs_kesehatan']); // 1% × 5jt
        $this->assertEquals(150000, $slip['potongan_bpjs_tk']);       // 3% × 5jt
        $this->assertEquals(12500, $slip['pph21']);
        $this->assertEquals(5000000, $slip['total_bruto']);
        $this->assertEquals(4387500, $slip['gaji_bersih']);
    }

    public function test_pengaturan_bpjs_default_dan_bisa_dikustomisasi(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $resDefault = $this->getJson('/api/payroll/pengaturan');
        $resDefault->assertStatus(200);
        $this->assertEquals(1.0, $resDefault->json('data.persen_bpjs_kesehatan'));
        $this->assertEquals(2.0, $resDefault->json('data.persen_bpjs_jht'));
        $this->assertEquals(1.0, $resDefault->json('data.persen_bpjs_jp'));
        $this->assertEquals(12000000.0, $resDefault->json('data.plafon_gaji_bpjs_kesehatan'));

        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload([
            'persen_bpjs_kesehatan' => 2,
            'persen_bpjs_jht'       => 3.7,
            'persen_bpjs_jp'        => 1,
            'plafon_gaji_bpjs_kesehatan' => 10000000,
        ]))->assertStatus(200);

        // Gaji 15jt, plafon kesehatan 10jt → BPJS Kes = 2% × 10jt = 200.000
        // BPJS TK = (3.7% + 1%) × 15jt = 705.000
        $this->makeKaryawan('Fani Bpjs', 'NIK-PAY-06', 15000000, [
            'ikut_bpjs_kesehatan' => 1,
            'ikut_bpjs_ketenagakerjaan' => 1,
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();
        $this->assertEquals(200000, $slip['potongan_bpjs_kesehatan']);
        $this->assertEquals(705000, $slip['potongan_bpjs_tk']);
    }

    public function test_ptkp_default_dan_bisa_dikustomisasi_dari_pengaturan(): void
    {
        $this->actingAsRole('SUPERADMIN');

        $resDefault = $this->getJson('/api/payroll/pengaturan');
        $resDefault->assertStatus(200);
        $this->assertEquals(54000000.0, $resDefault->json('data.ptkp_dasar'));
        $this->assertEquals(4500000.0, $resDefault->json('data.ptkp_tambahan'));

        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload([
            'ptkp_dasar'    => 36000000,
            'ptkp_tambahan' => 3000000,
        ]))->assertStatus(200);

        $resSetelah = $this->getJson('/api/payroll/pengaturan');
        $this->assertEquals(36000000.0, $resSetelah->json('data.ptkp_dasar'));
        $this->assertEquals(3000000.0, $resSetelah->json('data.ptkp_tambahan'));

        // Gaji 5jt K/1: neto tahunan = (5jt − 250rb) × 12 = 57jt
        // PTKP custom K/1 = 36jt + (2 × 3jt) = 42jt → PKP 15jt → 5% = 750rb/thn = 62.500/bln
        $this->makeKaryawan('Pandu Ptkp', 'NIK-PAY-PTKP', 5000000, [
            'status_ptkp' => 'K/1',
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();
        $this->assertEquals(62500, $slip['pph21']);
    }

    public function test_override_bpjs_per_karyawan_menang_atas_pengaturan_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        // Setting company-wide: Kesehatan 1%, JHT 2%, JP 1%, plafon 12jt
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());

        // Karyawan dengan override: Kesehatan 2%, JHT 3%, JP 1,5% — plafon tidak dioverride (ikut default 12jt)
        $idKaryawan = $this->makeKaryawan('Fani Override', 'NIK-PAY-07', 5000000, [
            'ikut_bpjs_kesehatan' => 1,
            'ikut_bpjs_ketenagakerjaan' => 1,
            'override_persen_bpjs_kesehatan' => 2,
            'override_persen_bpjs_jht'       => 3,
            'override_persen_bpjs_jp'        => 1.5,
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);

        // BPJS Kes = 2% × 5jt = 100.000 (bukan 1% × 5jt = 50.000 dari default perusahaan)
        $this->assertEquals(100000, $slip['potongan_bpjs_kesehatan']);
        // BPJS TK = (3% + 1,5%) × 5jt = 225.000 (bukan 3% default = 150.000)
        $this->assertEquals(225000, $slip['potongan_bpjs_tk']);
        $this->assertEquals(2, $slip['persen_bpjs_kesehatan']);
        $this->assertEquals(3, $slip['persen_bpjs_jht']);
        $this->assertEquals(1.5, $slip['persen_bpjs_jp']);
    }

    public function test_karyawan_tanpa_override_ikut_pengaturan_perusahaan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());

        $this->makeKaryawan('Gani Default', 'NIK-PAY-08', 5000000, [
            'ikut_bpjs_kesehatan' => 1,
            'ikut_bpjs_ketenagakerjaan' => 1,
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();
        $this->assertEquals(50000, $slip['potongan_bpjs_kesehatan']);  // 1% default
        $this->assertEquals(150000, $slip['potongan_bpjs_tk']);        // 3% default
    }

    public function test_pph21_terhitung_untuk_gaji_di_atas_ptkp(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());

        // Gaji 10jt TK/0: biaya jabatan 500rb → neto 9,5jt ×12 = 114jt; PKP 60jt
        // Pajak: 60jt×5% = 3.000.000/tahun → 250.000/bulan
        $this->makeKaryawan('Budi Pajak', 'NIK-PAY-02', 10000000, ['status_ptkp' => 'TK/0']);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $res = $this->getJson("/api/payroll/periode/{$idPeriode}");
        $slip = collect($res->json('data.slips'))->first();
        $this->assertEquals(250000, $slip['pph21']);
    }

    public function test_generate_slip_mengisi_tunjangan_dari_default_jabatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        $idJabatan = $this->makeJabatan(1000000);
        // Gaji 5jt + tunjangan jabatan 1jt = bruto 6jt, TK/0
        // Biaya jabatan 300rb -> neto 5,7jt x12 = 68,4jt; PKP 14,4jt x5% = 720rb/thn = 60.000/bln
        $this->makeKaryawan('Hari Jabatan', 'NIK-PAY-09', 5000000, [
            'id_jabatan' => $idJabatan, 'status_ptkp' => 'TK/0',
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();
        $this->assertEquals(1000000, $slip['tunjangan_lain']);
        $this->assertEquals(6000000, $slip['total_bruto']);
        $this->assertEquals(60000, $slip['pph21']);
        $this->assertEquals(5940000, $slip['gaji_bersih']);
    }

    public function test_override_tunjangan_jabatan_per_karyawan_menang_atas_default_jabatan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        $idJabatan = $this->makeJabatan(1000000);
        // Gaji 5jt + override tunjangan 2,5jt (bukan default jabatan 1jt) = bruto 7,5jt, TK/0
        // Biaya jabatan 375rb -> neto 7,125jt x12 = 85,5jt; PKP 31,5jt x5% = 1.575.000/thn = 131.250/bln
        $this->makeKaryawan('Indra Override Tunjangan', 'NIK-PAY-10', 5000000, [
            'id_jabatan' => $idJabatan, 'status_ptkp' => 'TK/0',
            'override_tunjangan_jabatan' => 2500000,
        ]);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();
        $this->assertEquals(2500000, $slip['tunjangan_lain']);
        $this->assertEquals(7500000, $slip['total_bruto']);
        $this->assertEquals(131250, $slip['pph21']);
        $this->assertEquals(7368750, $slip['gaji_bersih']);
    }

    public function test_generate_slip_prorata_karyawan_masuk_tengah_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        $idJabatan = $this->makeJabatan(310000);
        // Periode Juli 2026 (cutoff 1) = 31 hari; masuk 16 Jul -> aktif 16 hari (16 s/d 31 Jul)
        // Gaji 3.100.000 x 16/31 = 1.600.000; tunjangan 310.000 x 16/31 = 160.000
        $idKaryawan = $this->makeKaryawan('Joni Prorata Masuk', 'NIK-PAY-11', 3100000, [
            'id_jabatan' => $idJabatan, 'tanggal_masuk' => '2026-07-16',
        ]);
        // Masuk setelah periode berakhir -> tidak dapat slip
        $this->makeKaryawan('Karyawan Masa Depan', 'NIK-PAY-12', 4000000, [
            'tanggal_masuk' => '2026-08-10',
        ]);

        $idPeriode = $this->buatPeriode('2026-07');
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slips = $this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips');
        $this->assertCount(1, $slips);

        $slip = collect($slips)->firstWhere('id_karyawan', $idKaryawan);
        $this->assertNotNull($slip);
        $this->assertEquals(1600000, $slip['gaji_pokok']);
        $this->assertEquals(160000, $slip['tunjangan_lain']);
        $this->assertEquals(1760000, $slip['total_bruto']);
        $this->assertEquals(1760000, $slip['gaji_bersih']);
        $this->assertStringContainsString('16/31', $slip['catatan']);
    }

    public function test_generate_slip_prorata_karyawan_berhenti_tengah_periode(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        // Berhenti efektif 10 Jul -> aktif 10 hari (1 s/d 10 Jul); 3.100.000 x 10/31 = 1.000.000
        $idKaryawan = $this->makeKaryawan('Lani Prorata Berhenti', 'NIK-PAY-13', 3100000, ['aktif' => 0]);
        $this->makeExit($idKaryawan, '2026-07-10');
        // Non-aktif tanpa catatan exit -> tetap tidak dapat slip
        $this->makeKaryawan('Mati Tanpa Exit', 'NIK-PAY-14', 4000000, ['aktif' => 0]);

        $idPeriode = $this->buatPeriode('2026-07');
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slips = $this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips');
        $this->assertCount(1, $slips);

        $slip = collect($slips)->firstWhere('id_karyawan', $idKaryawan);
        $this->assertNotNull($slip);
        $this->assertEquals(1000000, $slip['gaji_pokok']);
        $this->assertEquals(1000000, $slip['gaji_bersih']);
        $this->assertStringContainsString('10/31', $slip['catatan']);
    }

    public function test_generate_slip_karyawan_berhenti_setelah_periode_dapat_gaji_penuh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload())->assertStatus(200);

        // Resign efektif 5 Agu (setelah periode Juli berakhir) -> gaji Juli tetap penuh
        // Gaji 5jt TK/0: PPh21 12.500/bln (sama seperti test generate komponen)
        $idKaryawan = $this->makeKaryawan('Nina Exit Agustus', 'NIK-PAY-15', 5000000, ['aktif' => 0]);
        $this->makeExit($idKaryawan, '2026-08-05');

        $idPeriode = $this->buatPeriode('2026-07');
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertNotNull($slip);
        $this->assertEquals(5000000, $slip['gaji_pokok']);
        $this->assertEquals(4987500, $slip['gaji_bersih']);
        $this->assertNull($slip['catatan']);
    }

    public function test_edit_slip_menghitung_ulang_total(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());
        $this->makeKaryawan('Citra Edit', 'NIK-PAY-03', 5000000);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();

        $res = $this->putJson("/api/payroll/slip/{$slip['id_slip']}", [
            'tunjangan_lain' => 500000,
            'keterangan_tunjangan' => 'Bonus kinerja',
            'potongan_lain' => 100000,
            'keterangan_potongan' => 'Cicilan seragam',
        ]);

        $res->assertStatus(200);
        $this->assertEquals(5500000, $res->json('data.total_bruto'));
        // potongan: pph21 12.500 (dari generate) + potongan_lain 100rb
        $this->assertEquals(5387500, $res->json('data.gaji_bersih'));
    }

    public function test_download_pdf_slip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());
        $this->makeKaryawan('Eka Pdf', 'NIK-PAY-05', 5000000);

        $idPeriode = $this->buatPeriode();
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();

        $res = $this->get("/api/payroll/slip/{$slip['id_slip']}/pdf");

        $res->assertStatus(200);
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('NIK-PAY-05', $res->headers->get('content-disposition'));
    }

    public function test_finalisasi_mengunci_slip_dan_regenerate(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/payroll/pengaturan', $this->pengaturanPayload());
        $this->makeKaryawan('Dodi Final', 'NIK-PAY-04', 5000000);

        $idPeriode = $this->buatPeriode();

        // finalisasi tanpa slip ditolak
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(422);

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        // setelah final: edit slip, generate ulang, dan hapus periode ditolak
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['tunjangan_lain' => 1])->assertStatus(422);
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(422);
        $this->deleteJson("/api/payroll/periode/{$idPeriode}")->assertStatus(422);

        // batal finalisasi -> kembali draft, bisa diedit lagi
        $this->postJson("/api/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(200);
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['tunjangan_lain' => 1])->assertStatus(200);
    }
}
