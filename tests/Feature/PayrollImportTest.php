<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PayrollImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private const HEADER = [
        'NO', 'NAMA', 'PROJECT', 'TYPE TRUCK', 'JABATAN', 'STATUS', 'BANK', 'NO REKENING',
        'Absen Masuk', 'GAJI POKOK', 'UANG MAKAN', 'TUNJANGAN', 'JUMLAH GAJI', 'GAJI PRORATE',
        'UANG MAKAN MINGGUAN', 'KASBON', 'UJ TERPAKAI', 'TILANGAN', 'TOTAL GAJI', 'KETERANGAN', 'CATATAN',
    ];

    private function makeKaryawan(string $nama, string $nik, float $gaji = 0, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => $nik, 'nama_karyawan' => $nama, 'aktif' => 1,
            'gaji_pokok' => $gaji, 'dibuat_pada' => now(),
        ], $extra));
        return $id;
    }

    private function makeKasbonLama(string $idKaryawan, float $nominal, float $cicilan): string
    {
        $id = (string) Str::uuid();
        DB::table('kasbon')->insert([
            'id_kasbon' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_kasbon' => 'KSB-TES-' . Str::random(6), 'id_karyawan' => $idKaryawan,
            'tanggal' => '2020-01-01', 'nominal' => $nominal, 'cicilan_per_periode' => $cicilan,
            'mulai_potong' => '2020-01-01', 'keperluan' => 'Kasbon lama', 'saldo_awal' => 1,
            'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatPeriode(?string $bulan = null): string
    {
        $this->putJson('/api/payroll/pengaturan', [
            'tanggal_mulai_cutoff'       => 1,
            'hari_kerja_per_bulan'       => 25,
            'persen_bpjs_kesehatan'      => 1,
            'persen_bpjs_jht'            => 2,
            'persen_bpjs_jp'             => 1,
            'plafon_gaji_bpjs_kesehatan' => 12000000,
        ])->assertStatus(200);

        $res = $this->postJson('/api/payroll/periode', ['bulan' => $bulan ?? now()->format('Y-m')]);
        $res->assertStatus(201);
        return $res->json('data.id_periode');
    }

    private function barisGaji(string $nama, array $override = []): array
    {
        $defaults = [
            'NO' => 1, 'NAMA' => $nama, 'PROJECT' => 'ASTRO KEMBANGAN', 'TYPE TRUCK' => 'CDE',
            'JABATAN' => 'Pengemudi', 'STATUS' => 'Kontrak', 'BANK' => 'BCA', 'NO REKENING' => '2880705027',
            'Absen Masuk' => 'Full', 'GAJI POKOK' => 2500000, 'UANG MAKAN' => 800000, 'TUNJANGAN' => 200000,
            'JUMLAH GAJI' => 3500000, 'GAJI PRORATE' => 0, 'UANG MAKAN MINGGUAN' => 250000, 'KASBON' => 0,
            'UJ TERPAKAI' => 0, 'TILANGAN' => 0, 'TOTAL GAJI' => 3250000, 'KETERANGAN' => null, 'CATATAN' => null,
        ];
        $row = array_merge($defaults, $override);
        return array_map(fn (string $h) => $row[$h], self::HEADER);
    }

    private function fileGaji(array $dataRows, bool $denganHeader = true): UploadedFile
    {
        $rows = [[null], [null], array_merge([null], range(1, 18))];
        if ($denganHeader) {
            $rows[] = self::HEADER;
        }
        foreach ($dataRows as $r) {
            $rows[] = $r;
        }
        $total = array_fill(0, count(self::HEADER), null);
        $total[18] = 127330322.58;
        $rows[] = $total;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'gaji') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'gaji-driver.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_import_excel_membuat_slip_dari_file(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Ade Sandico Marpaung', 'NIK-IMP-01');
        $this->makeKasbonLama($idKaryawan, 500000, 100000);
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([
            $this->barisGaji("  ade sandico marpaung \n", [
                'KASBON' => 100000, 'UJ TERPAKAI' => 50000, 'TILANGAN' => 25000,
                'KETERANGAN' => '06 Juli 2026', 'CATATAN' => 'CDD DUA TRIP',
            ]),
        ]);

        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1)
            ->assertJsonPath('data.gagal', [])
            ->assertJsonPath('data.selisih_kasbon', []);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertNotNull($slip);
        $this->assertEquals(2500000, $slip['gaji_pokok']);
        $this->assertEquals(800000, $slip['uang_makan']);
        $this->assertEquals(200000, $slip['tunjangan_lain']);
        $this->assertEquals(250000, $slip['uang_makan_mingguan']);
        $this->assertEquals(100000, $slip['kasbon']);
        $this->assertEquals(50000, $slip['uang_jalan_terpakai']);
        $this->assertEquals(25000, $slip['tilangan']);
        $this->assertSame('ASTRO KEMBANGAN', $slip['proyek']);
        $this->assertSame('CDE', $slip['tipe_truck']);
        $this->assertSame('Full', $slip['absen_masuk']);
        $this->assertEquals(3500000, $slip['total_bruto']);
        $this->assertEquals(425000, $slip['total_potongan']);
        $this->assertEquals(3075000, $slip['gaji_bersih']);
        $this->assertStringContainsString('06 Juli 2026', $slip['catatan']);
        $this->assertStringContainsString('CDD DUA TRIP', $slip['catatan']);
    }

    public function test_import_excel_mengabaikan_kolom_kasbon_dan_melaporkan_selisihnya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $tanpaKasbon = $this->makeKaryawan('Rani Tanpa Kasbon', 'NIK-IMP-11');
        $berkasbon   = $this->makeKaryawan('Seno Berkasbon', 'NIK-IMP-12');
        $this->makeKasbonLama($berkasbon, 900000, 300000);
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([
            $this->barisGaji('Rani Tanpa Kasbon', ['KASBON' => 100000]),
            $this->barisGaji('Seno Berkasbon', ['KASBON' => 0]),
        ]);

        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)->assertJsonPath('data.berhasil', 2);

        $selisih = collect($res->json('data.selisih_kasbon'));
        $this->assertCount(2, $selisih);
        $this->assertEquals(100000, $selisih->firstWhere('nama', 'Rani Tanpa Kasbon')['excel']);
        $this->assertEquals(0, $selisih->firstWhere('nama', 'Rani Tanpa Kasbon')['sistem']);
        $this->assertEquals(0, $selisih->firstWhere('nama', 'Seno Berkasbon')['excel']);
        $this->assertEquals(300000, $selisih->firstWhere('nama', 'Seno Berkasbon')['sistem']);

        $slips = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'));
        $slipRani = $slips->firstWhere('id_karyawan', $tanpaKasbon);
        $this->assertEquals(0, $slipRani['kasbon']);
        $this->assertEquals(250000, $slipRani['total_potongan']);
        $this->assertEquals(3250000, $slipRani['gaji_bersih']);

        $slipSeno = $slips->firstWhere('id_karyawan', $berkasbon);
        $this->assertEquals(300000, $slipSeno['kasbon']);
        $this->assertEquals(550000, $slipSeno['total_potongan']);
        $this->assertEquals(2950000, $slipSeno['gaji_bersih']);
    }

    public function test_import_setelah_generate_bergaji_nol_tetap_memotong_cicilan_terjadwal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Udin Gaji Dari Excel', 'NIK-IMP-13');
        $this->makeKasbonLama($idKaryawan, 2000000, 500000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slipAwal = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(0, $slipAwal['kasbon']);

        $file = $this->fileGaji([$this->barisGaji('Udin Gaji Dari Excel', ['KASBON' => 0])]);
        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $this->assertEquals(500000, $res->json('data.selisih_kasbon.0.sistem'));

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(500000, $slip['kasbon']);
        $this->assertEquals(750000, $slip['total_potongan']);
        $this->assertEquals(2750000, $slip['gaji_bersih']);
    }

    public function test_import_mempertahankan_koreksi_manual_kasbon_pada_slip(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Vina Koreksi Manual', 'NIK-IMP-14', 5000000);
        $this->makeKasbonLama($idKaryawan, 1000000, 200000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 50000])->assertStatus(200);

        $file = $this->fileGaji([$this->barisGaji('Vina Koreksi Manual', ['KASBON' => 200000])]);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])->assertStatus(200);

        $sesudah = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(50000, $sesudah['kasbon']);
    }

    public function test_koreksi_manual_kasbon_tidak_ikut_terpangkas_saat_import_bergaji_keliru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Xena Manual Bertahan', 'NIK-IMP-16', 5000000);
        $this->makeKasbonLama($idKaryawan, 1000000, 200000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 300000])->assertStatus(200);

        $keliru = $this->fileGaji([$this->barisGaji('Xena Manual Bertahan', [
            'GAJI POKOK' => 0, 'UANG MAKAN' => 0, 'TUNJANGAN' => 0, 'UANG MAKAN MINGGUAN' => 0,
        ])]);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $keliru])->assertStatus(200);
        $benar = $this->fileGaji([$this->barisGaji('Xena Manual Bertahan')]);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $benar])->assertStatus(200);

        $sesudah = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(300000, $sesudah['kasbon']);
    }

    public function test_import_melaporkan_baris_dengan_teks_terlalu_panjang_tanpa_menggagalkan_baris_lain(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan('Yuli Teks Panjang', 'NIK-IMP-17');
        $normal = $this->makeKaryawan('Zaki Normal', 'NIK-IMP-18');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([
            $this->barisGaji('Yuli Teks Panjang', ['Absen Masuk' => 'Masuk penuh satu bulan']),
            $this->barisGaji('Zaki Normal'),
        ]);

        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)->assertJsonPath('data.berhasil', 1);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertSame(5, $res->json('data.gagal.0.baris'));
        $this->assertStringContainsString('ABSEN MASUK', $res->json('data.gagal.0.alasan'));

        $slips = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'));
        $this->assertCount(1, $slips);
        $this->assertSame($normal, $slips->first()['id_karyawan']);
    }

    public function test_import_ulang_mengganti_kasbon_lama_dari_excel_dengan_angka_sistem(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Wawan Slip Lama', 'NIK-IMP-15');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([$this->barisGaji('Wawan Slip Lama', ['KASBON' => 100000])]);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])->assertStatus(200);
        DB::table('payroll_slip')->where('id_periode', $idPeriode)->where('id_karyawan', $idKaryawan)->update([
            'kasbon' => 100000, 'total_potongan' => 350000, 'gaji_bersih' => 3150000,
        ]);

        $ulang = $this->fileGaji([$this->barisGaji('Wawan Slip Lama', ['KASBON' => 100000])]);
        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $ulang]);
        $res->assertStatus(200);
        $this->assertEquals(100000, $res->json('data.selisih_kasbon.0.excel'));
        $this->assertEquals(0, $res->json('data.selisih_kasbon.0.sistem'));

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(0, $slip['kasbon']);
        $this->assertEquals(3250000, $slip['gaji_bersih']);
    }

    public function test_template_import_memakai_cicilan_kasbon_bila_slip_belum_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Tono Template Kasbon', 'NIK-TPL-05', 5000000);
        $this->makeKasbonLama($idKaryawan, 600000, 75000);
        $idPeriode = $this->buatPeriode();

        [, $rows] = $this->unduhTemplate($idPeriode);

        $baris = collect(array_slice($rows, 1))->first(fn ($r) => trim((string) $r[1]) === 'Tono Template Kasbon');
        $this->assertNotNull($baris);
        $this->assertEquals(75000, $baris[15]);
    }

    public function test_import_excel_memakai_gaji_prorate_jika_terisi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Mohamad Roni', 'NIK-IMP-02');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([
            $this->barisGaji('Mohamad Roni', [
                'Absen Masuk' => 16, 'GAJI POKOK' => 2500000, 'UANG MAKAN' => 800000,
                'TUNJANGAN' => 0, 'GAJI PRORATE' => 2030769.23, 'UANG MAKAN MINGGUAN' => 500000,
            ]),
        ]);

        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(2030769.23, $slip['gaji_pokok']);
        $this->assertSame('16', $slip['absen_masuk']);
        $this->assertEquals(2830769.23, $slip['total_bruto']);
        $this->assertEquals(500000, $slip['total_potongan']);
        $this->assertEquals(2330769.23, $slip['gaji_bersih']);
        $this->assertStringContainsString('prorata', strtolower($slip['catatan']));
    }

    public function test_import_excel_menghitung_sel_formula(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Indra Formula', 'NIK-IMP-10');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([
            $this->barisGaji('Indra Formula', [
                'UANG MAKAN MINGGUAN' => '=250000+250000+250000+250000+250000',
                'JUMLAH GAJI' => '=J5+K5+L5',
            ]),
        ]);

        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1);

        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertEquals(1250000, $slip['uang_makan_mingguan']);
        $this->assertEquals(1250000, $slip['total_potongan']);
    }

    public function test_import_excel_nama_tidak_ditemukan_dilaporkan_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([$this->barisGaji('Tidak Ada Orangnya')]);

        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 0);

        $gagal = $res->json('data.gagal');
        $this->assertCount(1, $gagal);
        $this->assertSame(5, $gagal[0]['baris']);
        $this->assertSame('Tidak Ada Orangnya', $gagal[0]['nama']);
        $this->assertStringContainsString('tidak ditemukan', $gagal[0]['alasan']);
    }

    public function test_import_excel_menimpa_slip_hasil_generate(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Budi Timpa', 'NIK-IMP-03', 5000000, [
            'ikut_bpjs_kesehatan' => 1,
            'ikut_bpjs_ketenagakerjaan' => 1,
            'status_ptkp' => 'TK/0',
        ]);
        $this->makeKasbonLama($idKaryawan, 1000000, 200000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);

        $file = $this->fileGaji([
            $this->barisGaji('Budi Timpa', [
                'GAJI POKOK' => 5000000, 'UANG MAKAN' => 800000, 'TUNJANGAN' => 0,
                'UANG MAKAN MINGGUAN' => 0, 'KASBON' => 200000,
            ]),
        ]);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('data.berhasil', 1);

        $slips = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->where('id_karyawan', $idKaryawan);
        $this->assertCount(1, $slips);

        $slip = $slips->first();
        $this->assertEquals(800000, $slip['uang_makan']);
        $this->assertEquals(200000, $slip['kasbon']);
        $this->assertEquals(50000, $slip['potongan_bpjs_kesehatan']);
        $this->assertEquals(150000, $slip['potongan_bpjs_tk']);
        $this->assertEquals(12500, $slip['pph21']);
        $this->assertEquals(5800000, $slip['total_bruto']);
        $this->assertEquals(412500, $slip['total_potongan']);
        $this->assertEquals(5387500, $slip['gaji_bersih']);
    }

    public function test_import_excel_periode_final_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan('Dodi Final Import', 'NIK-IMP-04', 5000000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $file = $this->fileGaji([$this->barisGaji('Dodi Final Import')]);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])->assertStatus(422);
    }

    public function test_import_excel_tanpa_header_nama_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan('Eka Tanpa Header', 'NIK-IMP-05');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([$this->barisGaji('Eka Tanpa Header')], denganHeader: false);
        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])->assertStatus(422);
    }

    public function test_import_excel_nama_duplikat_dalam_file_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan('Fani Dobel', 'NIK-IMP-06');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([
            $this->barisGaji('Fani Dobel'),
            $this->barisGaji('Fani Dobel'),
        ]);

        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 0);
        $this->assertCount(2, $res->json('data.gagal'));
        $this->assertStringContainsString('duplikat', $res->json('data.gagal.0.alasan'));
    }

    public function test_import_excel_nama_ganda_di_master_gagal(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->makeKaryawan('Gani Kembar', 'NIK-IMP-07');
        $this->makeKaryawan('Gani Kembar', 'NIK-IMP-08');
        $idPeriode = $this->buatPeriode();

        $file = $this->fileGaji([$this->barisGaji('Gani Kembar')]);

        $res = $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file]);
        $res->assertStatus(200)
            ->assertJsonPath('data.berhasil', 0);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertStringContainsString('lebih dari satu', $res->json('data.gagal.0.alasan'));
    }

    private function unduhTemplate(string $idPeriode): array
    {
        $res = $this->get("/api/payroll/periode/{$idPeriode}/import/template");
        $res->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'tpl-gaji') . '.xlsx';
        copy($res->baseResponse->getFile()->getPathname(), $path);
        $file = new UploadedFile($path, 'template-gaji.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $rows = \Maatwebsite\Excel\Facades\Excel::toArray(
            new \App\Modules\Payroll\Imports\PayrollImport(), $file
        )[0];

        return [$file, $rows];
    }

    public function test_template_import_berisi_semua_karyawan_dan_bisa_diupload_balik(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idJabatan = (string) Str::uuid();
        DB::table('jabatan')->insert([
            'id_jabatan' => $idJabatan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_jabatan' => 'JAB-TPL', 'nama_jabatan' => 'Pengemudi',
            'tunjangan_jabatan' => 0, 'dibuat_pada' => now(),
        ]);
        $this->makeKaryawan('Aldi Template', 'NIK-TPL-01', 5000000, [
            'id_jabatan' => $idJabatan, 'nama_bank' => 'BCA', 'nomor_rekening' => '2880705027',
        ]);
        $this->makeKaryawan('Bela Template', 'NIK-TPL-02', 3000000);
        $this->makeKaryawan('Coki Nonaktif', 'NIK-TPL-03', 4000000, ['aktif' => 0]);
        $idPeriode = $this->buatPeriode();

        [$file, $rows] = $this->unduhTemplate($idPeriode);

        $header = array_map(fn ($v) => strtoupper(trim((string) $v)), $rows[0]);
        $this->assertContains('NAMA', $header);
        $this->assertContains('GAJI POKOK', $header);
        $this->assertContains('UANG MAKAN MINGGUAN', $header);

        $namaSemua = array_map(fn ($r) => trim((string) $r[1]), array_slice($rows, 1));
        $this->assertContains('Aldi Template', $namaSemua);
        $this->assertContains('Bela Template', $namaSemua);
        $this->assertNotContains('Coki Nonaktif', $namaSemua);

        $barisAldi = collect(array_slice($rows, 1))->first(fn ($r) => trim((string) $r[1]) === 'Aldi Template');
        $this->assertSame('Pengemudi', trim((string) $barisAldi[4]));
        $this->assertSame('BCA', trim((string) $barisAldi[6]));
        $this->assertSame('2880705027', trim((string) $barisAldi[7]));
        $this->assertEquals(5000000, $barisAldi[9]);

        $this->post("/api/payroll/periode/{$idPeriode}/import", ['file' => $file])
            ->assertStatus(200)
            ->assertJsonPath('data.berhasil', 2)
            ->assertJsonPath('data.gagal', []);
    }

    public function test_template_import_memuat_nilai_slip_yang_sudah_ada(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Dini Prefill', 'NIK-TPL-04', 5000000);
        $this->makeKasbonLama($idKaryawan, 500000, 50000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", [
            'uang_makan' => 300000, 'kasbon' => 100000,
        ])->assertStatus(200);

        [, $rows] = $this->unduhTemplate($idPeriode);

        $baris = collect(array_slice($rows, 1))->first(fn ($r) => trim((string) $r[1]) === 'Dini Prefill');
        $this->assertNotNull($baris);
        $this->assertEquals(5000000, $baris[9]);
        $this->assertEquals(300000, $baris[10]);
        $this->assertEquals(100000, $baris[15]);
    }

    public function test_edit_slip_bisa_ubah_komponen_import(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Hani Edit Komponen', 'NIK-IMP-09', 4000000);
        $this->makeKasbonLama($idKaryawan, 500000, 50000);
        $idPeriode = $this->buatPeriode();

        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->json('data.slips'))->first();

        $res = $this->putJson("/api/payroll/slip/{$slip['id_slip']}", [
            'uang_makan'          => 300000,
            'uang_makan_mingguan' => 25000,
            'kasbon'              => 100000,
            'uang_jalan_terpakai' => 10000,
            'tilangan'            => 50000,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(300000, $res->json('data.uang_makan'));
        $this->assertEquals(4300000, $res->json('data.total_bruto'));
        $this->assertEquals(185000, $res->json('data.total_potongan'));
        $this->assertEquals(4115000, $res->json('data.gaji_bersih'));
    }
}
