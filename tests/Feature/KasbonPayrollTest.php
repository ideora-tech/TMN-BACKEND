<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KasbonPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePerusahaan();
        app(\App\Modules\ArusKas\ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);
    }

    private function makeKaryawan(string $nama, float $gaji = 5000000, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert(array_merge([
            'id_karyawan' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-KP-' . Str::random(6), 'nama_karyawan' => $nama, 'aktif' => 1,
            'gaji_pokok' => $gaji, 'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function makeKasbonLama(string $idKaryawan, float $nominal, float $cicilan, array $extra = []): string
    {
        $id = (string) Str::uuid();
        DB::table('kasbon')->insert(array_merge([
            'id_kasbon' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nomor_kasbon' => 'KSB-TES-' . Str::random(6), 'id_karyawan' => $idKaryawan,
            'tanggal' => '2026-06-15', 'nominal' => $nominal, 'cicilan_per_periode' => $cicilan,
            'mulai_potong' => '2026-07-01', 'keperluan' => 'Kasbon lama', 'saldo_awal' => 1,
            'dibuat_pada' => now(),
        ], $extra));

        return $id;
    }

    private function buatPeriode(string $bulan = '2026-07'): string
    {
        $this->putJson('/api/payroll/pengaturan', [
            'tanggal_mulai_cutoff'       => 1,
            'hari_kerja_per_bulan'       => 25,
            'persen_bpjs_kesehatan'      => 1,
            'persen_bpjs_jht'            => 2,
            'persen_bpjs_jp'             => 1,
            'plafon_gaji_bpjs_kesehatan' => 12000000,
        ])->assertStatus(200);

        $res = $this->postJson('/api/payroll/periode', ['bulan' => $bulan]);
        $res->assertStatus(201);

        return (string) $res->json('data.id_periode');
    }

    private function generate(string $idPeriode): void
    {
        $this->postJson("/api/payroll/periode/{$idPeriode}/generate")->assertStatus(200);
    }

    private function slip(string $idPeriode, string $idKaryawan): array
    {
        $slip = collect($this->getJson("/api/payroll/periode/{$idPeriode}")->assertStatus(200)->json('data.slips'))
            ->firstWhere('id_karyawan', $idKaryawan);
        $this->assertNotNull($slip);

        return $slip;
    }

    private function terbayar(string $idKasbon): float
    {
        return (float) DB::table('kasbon_pembayaran')
            ->where('id_kasbon', $idKasbon)
            ->whereNull('dihapus_pada')
            ->sum('nominal');
    }

    public function test_generate_slip_memotong_cicilan_kasbon_berjalan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Andi Kasbon', 5000000, ['status_ptkp' => 'TK/0']);
        $tanpaKasbon = $this->makeKaryawan('Bela Tanpa Kasbon');
        $this->makeKasbonLama($idKaryawan, 1000000, 250000);
        $idPeriode = $this->buatPeriode();

        $this->generate($idPeriode);

        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(250000, $slip['kasbon']);
        $this->assertEquals(12500, $slip['pph21']);
        $this->assertEquals(262500, $slip['total_potongan']);
        $this->assertEquals(4737500, $slip['gaji_bersih']);
        $this->assertEquals(1000000, $slip['sisa_kasbon']);
        $this->assertEquals(250000, $slip['rencana_kasbon']);

        $lain = $this->slip($idPeriode, $tanpaKasbon);
        $this->assertEquals(0, $lain['kasbon']);
        $this->assertEquals(0, $lain['sisa_kasbon']);
        $this->assertEquals(0, $lain['rencana_kasbon']);

        $this->assertSame(0, DB::table('kasbon_pembayaran')->count());
    }

    public function test_kasbon_yang_belum_dicairkan_tidak_dipotong(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Cici Menunggu');
        $idPeriode = $this->buatPeriode(now()->format('Y-m'));

        $idKasbon = (string) $this->postJson('/api/kasbon', [
            'id_karyawan' => $idKaryawan, 'tanggal' => now()->toDateString(), 'nominal' => 600000,
            'cicilan_per_periode' => 200000, 'mulai_potong' => now()->format('Y-m'), 'keperluan' => 'Berobat',
        ])->assertStatus(201)->assertJsonPath('data.status', 'menunggu_pencairan')->json('data.id_kasbon');

        $this->generate($idPeriode);
        $this->assertEquals(0, $this->slip($idPeriode, $idKaryawan)['kasbon']);

        $idPengajuan = (string) DB::table('kasbon')->where('id_kasbon', $idKasbon)->value('id_pengajuan');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        $this->generate($idPeriode);
        $this->assertEquals(200000, $this->slip($idPeriode, $idKaryawan)['kasbon']);
    }

    public function test_kasbon_baru_dipotong_mulai_bulan_gajian_yang_dipilih(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Dedi Mulai Agustus');
        $this->makeKasbonLama($idKaryawan, 1000000, 250000, ['mulai_potong' => '2026-08-01']);

        $juli = $this->buatPeriode('2026-07');
        $this->generate($juli);
        $slipJuli = $this->slip($juli, $idKaryawan);
        $this->assertEquals(0, $slipJuli['kasbon']);
        $this->assertEquals(1000000, $slipJuli['sisa_kasbon']);
        $this->assertEquals(0, $slipJuli['rencana_kasbon']);

        $agustus = $this->buatPeriode('2026-08');
        $this->generate($agustus);
        $this->assertEquals(250000, $this->slip($agustus, $idKaryawan)['kasbon']);
    }

    public function test_cicilan_dibatasi_sisa_dan_beberapa_kasbon_dijumlahkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Eko Dua Kasbon');
        $this->makeKasbonLama($idKaryawan, 100000, 250000);
        $this->makeKasbonLama($idKaryawan, 1000000, 200000);
        $idPeriode = $this->buatPeriode();

        $this->generate($idPeriode);

        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(300000, $slip['kasbon']);
        $this->assertEquals(1100000, $slip['sisa_kasbon']);
    }

    public function test_potongan_kasbon_tidak_membuat_gaji_bersih_minus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Fajar Gaji Kecil', 300000);
        $this->makeKasbonLama($idKaryawan, 2000000, 500000);
        $idPeriode = $this->buatPeriode();

        $this->generate($idPeriode);

        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(300000, $slip['kasbon']);
        $this->assertEquals(0, $slip['gaji_bersih']);
        $this->assertEquals(500000, $slip['rencana_kasbon']);
    }

    public function test_finalisasi_memposting_potongan_dan_batal_finalisasi_membatalkannya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Gilang Final');
        $idKasbon = $this->makeKasbonLama($idKaryawan, 1000000, 250000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $idSlip = $this->slip($idPeriode, $idKaryawan)['id_slip'];

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $this->assertDatabaseHas('kasbon_pembayaran', [
            'id_kasbon' => $idKasbon, 'nominal' => 250000, 'sumber' => 'payroll',
            'id_periode' => $idPeriode, 'id_slip' => $idSlip, 'dihapus_pada' => null,
        ]);
        $detail = $this->getJson("/api/kasbon/{$idKasbon}")->assertStatus(200);
        $detail->assertJsonPath('data.terbayar', 250000)
            ->assertJsonPath('data.sisa', 750000)
            ->assertJsonPath('data.status', 'berjalan')
            ->assertJsonPath('data.bisa_diubah', false)
            ->assertJsonPath('data.pembayaran.0.sumber', 'payroll')
            ->assertJsonPath('data.pembayaran.0.id_periode', $idPeriode)
            ->assertJsonPath('data.pembayaran.0.bisa_dihapus', false);
        $this->assertNotEmpty($detail->json('data.pembayaran.0.nama_periode'));

        $idPembayaran = $detail->json('data.pembayaran.0.id_kasbon_pembayaran');
        $this->deleteJson("/api/kasbon/{$idKasbon}/pelunasan/{$idPembayaran}")->assertStatus(409);

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_periode', $idPeriode)->whereNull('dihapus_pada')->first();
        $this->assertEquals(4737500, (float) $pengajuan->nominal);

        $this->postJson("/api/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(200);
        $this->assertEquals(0, $this->terbayar($idKasbon));
        $this->getJson("/api/kasbon/{$idKasbon}")->assertJsonPath('data.sisa', 1000000)->assertJsonPath('data.pembayaran', []);

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->assertEquals(250000, $this->terbayar($idKasbon));
        $this->assertSame(2, DB::table('kasbon_pembayaran')->where('id_kasbon', $idKasbon)->count());
    }

    public function test_periode_berikutnya_memotong_dari_sisa_setelah_finalisasi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Hadi Lunas');
        $idKasbon = $this->makeKasbonLama($idKaryawan, 400000, 250000);

        $juli = $this->buatPeriode('2026-07');
        $this->generate($juli);
        $this->postJson("/api/payroll/periode/{$juli}/finalisasi")->assertStatus(200);

        $agustus = $this->buatPeriode('2026-08');
        $this->generate($agustus);
        $this->assertEquals(150000, $this->slip($agustus, $idKaryawan)['kasbon']);
        $this->postJson("/api/payroll/periode/{$agustus}/finalisasi")->assertStatus(200);

        $this->getJson("/api/kasbon/{$idKasbon}")
            ->assertJsonPath('data.sisa', 0)
            ->assertJsonPath('data.status', 'lunas');

        $september = $this->buatPeriode('2026-09');
        $this->generate($september);
        $this->assertEquals(0, $this->slip($september, $idKaryawan)['kasbon']);
    }

    public function test_potongan_di_atas_cicilan_dialokasikan_ke_kasbon_tertua_lebih_dulu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Indra Dua Kasbon');
        $tua  = $this->makeKasbonLama($idKaryawan, 300000, 100000, ['tanggal' => '2026-01-10']);
        $muda = $this->makeKasbonLama($idKaryawan, 500000, 100000, ['tanggal' => '2026-02-10']);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(200000, $slip['kasbon']);

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 450000])->assertStatus(200);
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $this->assertEquals(300000, $this->terbayar($tua));
        $this->assertEquals(150000, $this->terbayar($muda));
        $this->getJson("/api/kasbon/{$tua}")->assertJsonPath('data.status', 'lunas');
        $this->getJson("/api/kasbon/{$muda}")->assertJsonPath('data.sisa', 350000);
    }

    public function test_potongan_di_bawah_cicilan_dialokasikan_ke_kasbon_tertua_lebih_dulu(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Joko Keringanan');
        $tua  = $this->makeKasbonLama($idKaryawan, 300000, 100000, ['tanggal' => '2026-01-10']);
        $muda = $this->makeKasbonLama($idKaryawan, 500000, 100000, ['tanggal' => '2026-02-10']);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 130000])->assertStatus(200);
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $this->assertEquals(100000, $this->terbayar($tua));
        $this->assertEquals(30000, $this->terbayar($muda));
    }

    public function test_finalisasi_ditolak_bila_potongan_kasbon_melebihi_sisa_di_sistem(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Kiki Angka Excel');
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        DB::table('payroll_slip')->where('id_periode', $idPeriode)->where('id_karyawan', $idKaryawan)->update([
            'kasbon' => 500000, 'total_potongan' => DB::raw('total_potongan + 500000'), 'gaji_bersih' => DB::raw('gaji_bersih - 500000'),
        ]);

        $res = $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi");

        $res->assertStatus(422);
        $this->assertStringContainsString('Kiki Angka Excel', (string) $res->json('message'));
        $this->assertSame('draft', DB::table('payroll_periode')->where('id_periode', $idPeriode)->value('status'));
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->where('id_periode', $idPeriode)->count());
        $this->assertSame(0, DB::table('kasbon_pembayaran')->count());

        $this->makeKasbonLama($idKaryawan, 500000, 100000);
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->assertSame(1, DB::table('kasbon_pembayaran')->where('nominal', 500000)->count());
    }

    public function test_pelunasan_manual_setelah_slip_dibuat_menahan_finalisasi_sampai_slip_dikoreksi(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Lina Lunas Duluan');
        $idKasbon = $this->makeKasbonLama($idKaryawan, 250000, 250000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(250000, $slip['kasbon']);

        $this->postJson("/api/kasbon/{$idKasbon}/pelunasan", ['tanggal' => now()->toDateString(), 'nominal' => 250000, 'keterangan' => 'Bayar tunai'])
            ->assertStatus(201)->assertJsonPath('data.status', 'lunas');

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(422);

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 0])->assertStatus(200);
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->assertEquals(250000, $this->terbayar($idKasbon));
    }

    public function test_finalisasi_ditolak_bila_slip_berkasbon_gajinya_minus(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Mira Minus');
        $this->makeKasbonLama($idKaryawan, 1000000, 250000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['potongan_lain' => 9000000])->assertStatus(200);

        $res = $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi");
        $res->assertStatus(422);
        $this->assertStringContainsString('Mira Minus', (string) $res->json('message'));
        $this->assertSame(0, DB::table('kasbon_pembayaran')->count());
    }

    public function test_koreksi_slip_membatasi_kasbon_pada_sisa_di_sistem(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Nanda Koreksi');
        $lama = $this->makeKaryawan('Oki Slip Lama');
        $this->makeKasbonLama($idKaryawan, 1000000, 250000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 1000001])->assertStatus(422);
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 1000000])
            ->assertStatus(200)->assertJsonPath('data.kasbon', 1000000);
        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 0])
            ->assertStatus(200)->assertJsonPath('data.kasbon', 0);

        $slipLama = $this->slip($idPeriode, $lama);
        DB::table('payroll_slip')->where('id_slip', $slipLama['id_slip'])->update(['kasbon' => 500000]);
        $this->putJson("/api/payroll/slip/{$slipLama['id_slip']}", ['kasbon' => 500000, 'tunjangan_lain' => 100000])
            ->assertStatus(200)->assertJsonPath('data.kasbon', 500000);
        $this->putJson("/api/payroll/slip/{$slipLama['id_slip']}", ['kasbon' => 400000])->assertStatus(422);
    }

    public function test_karyawan_berhenti_dipotong_seluruh_sisa_kasbon_di_gaji_terakhir(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Putu Resign', 3100000, ['aktif' => 0]);
        DB::table('karyawan_exit')->insert([
            'id_exit' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'jenis_exit' => 'resign',
            'tanggal_efektif' => '2026-07-10', 'dibuat_pada' => now(),
        ]);
        $idKasbon = $this->makeKasbonLama($idKaryawan, 800000, 100000, ['mulai_potong' => '2026-12-01']);
        $idPeriode = $this->buatPeriode();

        $this->generate($idPeriode);

        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(1000000, $slip['gaji_pokok']);
        $this->assertEquals(800000, $slip['kasbon']);
        $this->assertEquals(200000, $slip['gaji_bersih']);
        $this->assertStringContainsString('10/31', $slip['catatan']);
        $this->assertStringContainsString('kasbon', strtolower($slip['catatan']));

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->getJson("/api/kasbon/{$idKasbon}")->assertJsonPath('data.status', 'lunas');
    }

    public function test_potongan_yang_dibatasi_gaji_bersih_dibulatkan_ke_rupiah_penuh(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Qori Prorata', 5000000, [
            'aktif' => 0, 'ikut_bpjs_kesehatan' => 1, 'ikut_bpjs_ketenagakerjaan' => 1,
        ]);
        DB::table('karyawan_exit')->insert([
            'id_exit' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan' => $idKaryawan, 'jenis_exit' => 'resign',
            'tanggal_efektif' => '2026-07-17', 'dibuat_pada' => now(),
        ]);
        $idKasbon = $this->makeKasbonLama($idKaryawan, 5000000, 500000);
        $idPeriode = $this->buatPeriode();

        $this->generate($idPeriode);

        $slip = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(2741935.48, $slip['gaji_pokok']);
        $this->assertEquals(2632258, $slip['kasbon']);
        $this->assertSame(0.0, fmod((float) $slip['kasbon'], 1.0));
        $this->assertEqualsWithDelta(0.07, (float) $slip['gaji_bersih'], 0.001);
        $this->assertStringContainsString('masih ada sisa', $slip['catatan']);

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->getJson("/api/kasbon/{$idKasbon}")
            ->assertJsonPath('data.sisa', 2367742)
            ->assertJsonPath('data.status', 'berjalan');
    }

    public function test_finalisasi_menyimpan_sisa_kasbon_di_slip_dan_batal_finalisasi_mengosongkannya(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Rina Slip');
        $tanpaKasbon = $this->makeKaryawan('Seto Tanpa');
        $this->makeKasbonLama($idKaryawan, 600000, 100000);
        $this->makeKasbonLama($idKaryawan, 400000, 150000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);
        $slipLain = $this->slip($idPeriode, $tanpaKasbon);
        $this->assertEquals(250000, $slip['kasbon']);

        $this->get("/api/payroll/slip/{$slip['id_slip']}/pdf")->assertStatus(200);

        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);
        $this->assertEquals(750000, (float) DB::table('payroll_slip')->where('id_slip', $slip['id_slip'])->value('sisa_kasbon_setelah_potong'));
        $this->assertNull(DB::table('payroll_slip')->where('id_slip', $slipLain['id_slip'])->value('sisa_kasbon_setelah_potong'));
        $this->get("/api/payroll/slip/{$slip['id_slip']}/pdf")->assertStatus(200);

        $this->postJson("/api/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(200);
        $this->assertNull(DB::table('payroll_slip')->where('id_slip', $slip['id_slip'])->value('sisa_kasbon_setelah_potong'));
    }

    public function test_batal_finalisasi_yang_ditolak_karena_gaji_sudah_ditransfer_tidak_membatalkan_potongan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Tari Transfer');
        $idKasbon = $this->makeKasbonLama($idKaryawan, 1000000, 250000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $this->postJson("/api/payroll/periode/{$idPeriode}/finalisasi")->assertStatus(200);

        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_periode', $idPeriode)->whereNull('dihapus_pada')->value('id_pengajuan');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        $this->postJson("/api/payroll/periode/{$idPeriode}/batal-finalisasi")->assertStatus(409);

        $this->assertEquals(250000, $this->terbayar($idKasbon));
        $this->assertSame('final', DB::table('payroll_periode')->where('id_periode', $idPeriode)->value('status'));
    }

    public function test_koreksi_kasbon_di_slip_ditandai_manual_hanya_bila_nilainya_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idKaryawan = $this->makeKaryawan('Umar Manual');
        $this->makeKasbonLama($idKaryawan, 1000000, 250000);
        $idPeriode = $this->buatPeriode();
        $this->generate($idPeriode);
        $slip = $this->slip($idPeriode, $idKaryawan);

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 250000, 'tunjangan_lain' => 50000])->assertStatus(200);
        $this->assertSame(0, (int) DB::table('payroll_slip')->where('id_slip', $slip['id_slip'])->value('kasbon_manual'));

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 100000])->assertStatus(200);
        $this->assertSame(1, (int) DB::table('payroll_slip')->where('id_slip', $slip['id_slip'])->value('kasbon_manual'));

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 250000])->assertStatus(200);
        $this->assertSame(0, (int) DB::table('payroll_slip')->where('id_slip', $slip['id_slip'])->value('kasbon_manual'));

        $this->putJson("/api/payroll/slip/{$slip['id_slip']}", ['kasbon' => 100000])->assertStatus(200);

        $this->generate($idPeriode);
        $baru = $this->slip($idPeriode, $idKaryawan);
        $this->assertEquals(250000, $baru['kasbon']);
        $this->assertSame(0, (int) DB::table('payroll_slip')->where('id_slip', $baru['id_slip'])->value('kasbon_manual'));
    }
}
