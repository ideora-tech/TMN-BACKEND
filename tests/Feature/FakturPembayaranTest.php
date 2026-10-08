<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\Faktur\FakturModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FakturPembayaranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ensurePerusahaan();
    }

    private function makeKlien(string $nama = 'PT Klien Uji', ?string $idPerusahaan = null): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien' => $id, 'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'kode_klien' => 'KLN-' . Str::random(6), 'nama_klien' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function buatFaktur(float $total, string $status = 'terkirim', ?string $jatuhTempo = null, ?string $idKlien = null): FakturModel
    {
        return FakturModel::create([
            'id_perusahaan'  => self::PERUSAHAAN_ID,
            'id_klien'       => $idKlien ?? $this->makeKlien(),
            'nomor_faktur'   => 'INV-' . Str::upper(Str::random(8)),
            'total'          => $total,
            'status'         => $status,
            'tanggal_faktur' => now()->subDays(20)->toDateString(),
            'jatuh_tempo'    => $jatuhTempo,
        ]);
    }

    private function bayar(FakturModel $faktur, array $payload): TestResponse
    {
        return $this->postJson("/api/faktur/{$faktur->id_faktur}/pembayaran", array_merge(['tanggal_bayar' => now()->toDateString()], $payload));
    }

    private function hapus(FakturModel $faktur, string $idPembayaran, array $payload = ['alasan' => 'Salah input nominal']): TestResponse
    {
        return $this->deleteJson("/api/faktur/{$faktur->id_faktur}/pembayaran/{$idPembayaran}", $payload);
    }

    private function statusFaktur(FakturModel $faktur): string
    {
        return (string) DB::table('faktur')->where('id_faktur', $faktur->id_faktur)->value('status');
    }

    public function test_pembayaran_bertahap_melunasi_invoice_secara_otomatis(): void
    {
        $this->actingAsRole('KEUANGAN');
        $faktur = $this->buatFaktur(10000000);

        $res = $this->bayar($faktur, [
            'tanggal_bayar' => now()->subDays(5)->toDateString(), 'nominal' => 4000000,
            'no_referensi' => 'TRF-001', 'catatan' => 'Termin 1', 'bukti' => UploadedFile::fake()->image('tf.jpg'),
        ])->assertStatus(201);
        $res->assertJsonPath('message', 'Pembayaran dicatat')
            ->assertJsonPath('data.status', 'terkirim')
            ->assertJsonPath('data.tanggal_lunas', null)
            ->assertJsonCount(1, 'data.pembayaran')
            ->assertJsonPath('data.pembayaran.0.no_referensi', 'TRF-001')
            ->assertJsonPath('data.pembayaran.0.catatan', 'Termin 1');
        $this->assertSame(4000000.0, (float) $res->json('data.terbayar'));
        $this->assertSame(6000000.0, (float) $res->json('data.sisa'));
        $this->assertNotNull($res->json('data.pembayaran.0.url_bukti'));

        $res = $this->bayar($faktur, ['nominal' => 5800000, 'potongan' => 200000, 'keterangan_potongan' => 'PPh 23'])->assertStatus(201);
        $res->assertJsonPath('message', 'Pembayaran dicatat — invoice lunas')
            ->assertJsonPath('data.status', 'lunas')
            ->assertJsonPath('data.tanggal_lunas', now()->toDateString())
            ->assertJsonCount(2, 'data.pembayaran');
        $this->assertSame(9800000.0, (float) $res->json('data.diterima'));
        $this->assertSame(200000.0, (float) $res->json('data.potongan_bayar'));
        $this->assertSame(10000000.0, (float) $res->json('data.terbayar'));
        $this->assertSame(0.0, (float) $res->json('data.sisa'));

        $log = array_column($res->json('data.riwayat_status'), 'status');
        $this->assertSame(2, count(array_keys($log, 'pembayaran', true)));
        $this->assertContains('lunas', $log);
        $this->assertDatabaseHas('faktur_status_log', [
            'id_faktur' => $faktur->id_faktur, 'status' => 'pembayaran',
            'keterangan' => 'Diterima Rp 5.800.000, potongan Rp 200.000 (PPh 23) — tagihan lunas',
        ]);

        $this->bayar($faktur, ['nominal' => 1000])->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran hanya bisa dicatat untuk invoice berstatus terkirim');
    }

    public function test_validasi_pembayaran(): void
    {
        $this->actingAsRole('KEUANGAN');
        $faktur = $this->buatFaktur(10000000);

        $this->bayar($faktur, ['nominal' => 10000001])->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran melebihi sisa tagihan (Rp 10.000.000)');
        $this->bayar($faktur, ['nominal' => 9900000, 'potongan' => 200000, 'keterangan_potongan' => 'PPh 23'])->assertStatus(422);
        $this->bayar($faktur, ['nominal' => 0])->assertStatus(422)->assertJsonPath('message', 'Isi nominal yang diterima atau potongannya');
        $this->bayar($faktur, ['nominal' => 1000000, 'potongan' => 20000])->assertStatus(422)
            ->assertJsonPath('message', 'Isi keterangan potongan, misalnya PPh 23 atau biaya transfer');
        $this->bayar($faktur, ['nominal' => 0, 'potongan' => 1000001, 'keterangan_potongan' => 'Klaim'])->assertStatus(422)
            ->assertJsonPath('message', 'Total potongan melebihi 10% nilai invoice (maksimal Rp 1.000.000) — selisih sebesar itu perlu koreksi invoice, bukan potongan');
        $this->bayar($faktur, ['nominal' => 1000000, 'tanggal_bayar' => now()->addDay()->toDateString()])->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_bayar']);
        $this->bayar($faktur, ['nominal' => 1000000, 'tanggal_bayar' => now()->format('d-m-Y')])->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_bayar']);
        $this->bayar($faktur, ['nominal' => 1000000, 'tanggal_bayar' => '1990-01-01'])->assertStatus(422)
            ->assertJsonValidationErrors(['tanggal_bayar']);
        $this->bayar($faktur, [])->assertStatus(422)->assertJsonValidationErrors(['nominal']);
        $this->assertSame(0, DB::table('pembayaran_faktur')->count());

        foreach (['draft', 'menunggu_approval', 'batal'] as $status) {
            $this->bayar($this->buatFaktur(500000, $status), ['nominal' => 100000])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('pembayaran_faktur')->count());

        $this->bayar($faktur, ['nominal' => 4000000, 'potongan' => 600000, 'keterangan_potongan' => 'PPh'])->assertStatus(201);
        $this->bayar($faktur, ['nominal' => 1000000, 'potongan' => 400001, 'keterangan_potongan' => 'PPh'])->assertStatus(422);
        $this->bayar($faktur, ['nominal' => 1000000, 'potongan' => 400000, 'keterangan_potongan' => 'PPh'])->assertStatus(201);
    }

    public function test_total_berdesimal_menerima_pembulatan_ke_rupiah_terdekat(): void
    {
        $this->actingAsRole('KEUANGAN');
        $naik = $this->buatFaktur(13703702.58);
        $this->bayar($naik, ['nominal' => 13703704])->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran melebihi sisa tagihan (Rp 13.703.703)');
        $res = $this->bayar($naik, ['nominal' => 13703703])->assertStatus(201)->assertJsonPath('data.status', 'lunas');
        $this->assertSame(0.0, (float) $res->json('data.sisa'));

        $turun = $this->buatFaktur(1000.40);
        $res = $this->bayar($turun, ['nominal' => 1000])->assertStatus(201)->assertJsonPath('data.status', 'lunas');
        $this->assertSame(0.0, (float) $res->json('data.sisa'));
        $this->assertDatabaseHas('faktur_status_log', ['id_faktur' => $turun->id_faktur, 'status' => 'pembayaran', 'keterangan' => 'Diterima Rp 1.000 — tagihan lunas']);
    }

    public function test_hapus_pembayaran_wajib_alasan_dan_mengembalikan_invoice_lunas_ke_terkirim(): void
    {
        $this->actingAsRole('KEUANGAN');
        $faktur = $this->buatFaktur(3000000);
        $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(201);
        $res = $this->bayar($faktur, ['nominal' => 2000000])->assertStatus(201)->assertJsonPath('data.status', 'lunas');
        $idPelunasan = $res->json('data.pembayaran.1.id_pembayaran_faktur');

        $this->hapus($faktur, $idPelunasan, [])->assertStatus(422)->assertJsonValidationErrors(['alasan']);
        $this->hapus($faktur, (string) Str::uuid())->assertStatus(404);
        $this->assertSame('lunas', $this->statusFaktur($faktur));

        $res = $this->hapus($faktur, $idPelunasan)->assertStatus(200);
        $res->assertJsonPath('data.status', 'terkirim')
            ->assertJsonPath('data.tanggal_lunas', null)
            ->assertJsonCount(1, 'data.pembayaran');
        $this->assertSame(2000000.0, (float) $res->json('data.sisa'));
        $log = DB::table('faktur_status_log')->where('id_faktur', $faktur->id_faktur)->where('status', 'pembayaran_dihapus')->value('keterangan');
        $this->assertStringContainsString('Pembayaran Rp 2.000.000', (string) $log);
        $this->assertStringEndsWith('dihapus: Salah input nominal', (string) $log);

        $this->hapus($faktur, $idPelunasan)->assertStatus(404);
    }

    public function test_tandai_lunas_manual_hanya_bila_tagihan_sudah_habis(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = $this->buatFaktur(5000000);
        $this->bayar($faktur, ['nominal' => 1500000])->assertStatus(201);

        $this->patchJson("/api/faktur/{$faktur->id_faktur}/status", ['status' => 'lunas'])->assertStatus(422)
            ->assertJsonPath('message', 'Invoice lunas otomatis saat tagihannya habis — catat pembayarannya lewat Catat Pembayaran');
        $this->assertSame('terkirim', $this->statusFaktur($faktur));
        $this->assertSame(1, DB::table('pembayaran_faktur')->where('id_faktur', $faktur->id_faktur)->count());

        $gratis = $this->buatFaktur(0);
        $this->patchJson("/api/faktur/{$gratis->id_faktur}/status", ['status' => 'lunas'])->assertStatus(200)
            ->assertJsonPath('data.status', 'lunas')
            ->assertJsonPath('data.tanggal_lunas', now()->toDateString());
        $this->assertSame(0, DB::table('pembayaran_faktur')->where('id_faktur', $gratis->id_faktur)->count());
    }

    public function test_invoice_yang_sudah_menerima_pembayaran_tidak_bisa_dibatalkan(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = $this->buatFaktur(5000000);
        $res = $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(201);
        $idPembayaran = $res->json('data.pembayaran.0.id_pembayaran_faktur');

        $this->patchJson("/api/faktur/{$faktur->id_faktur}/status", ['status' => 'batal'])->assertStatus(422)
            ->assertJsonPath('message', 'Invoice ini sudah menerima pembayaran — hapus pembayarannya dulu sebelum membatalkan');
        $this->assertSame('terkirim', $this->statusFaktur($faktur));

        $this->hapus($faktur, $idPembayaran)->assertStatus(200);
        $this->patchJson("/api/faktur/{$faktur->id_faktur}/status", ['status' => 'batal'])->assertStatus(200)->assertJsonPath('data.status', 'batal');
    }

    public function test_piutang_dan_pembayaran_dijaga_izin_menu_piutang(): void
    {
        $faktur = $this->buatFaktur(5000000);

        foreach (['SALES', 'DISPATCHER'] as $peran) {
            $this->actingAsRole($peran);
            $this->getJson('/api/faktur/outstanding')->assertStatus(403);
            $this->get('/api/faktur/outstanding/export/excel')->assertStatus(403);
            $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(403);
        }

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/faktur/outstanding')->assertStatus(200)->assertJsonPath('data.ringkasan.jumlah_invoice', 1);
        $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(403);
        $this->assertSame(0, DB::table('pembayaran_faktur')->count());

        $this->actingAsRole('KEUANGAN');
        $res = $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(201);
        $idPembayaran = $res->json('data.pembayaran.0.id_pembayaran_faktur');

        $this->actingAsRole('MANAGER');
        $this->hapus($faktur, $idPembayaran)->assertStatus(403);
        $idMenuFaktur = DB::table('menu')->where('path', '/faktur')->value('id_menu');
        if ($idMenuFaktur === null) {
            $idMenuFaktur = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenuFaktur, 'nama_menu' => 'Invoice', 'path' => '/faktur', 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => 'SALES',
            'id_menu' => $idMenuFaktur, 'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
        $this->actingAsRole('SALES');
        $this->getJson("/api/faktur/{$faktur->id_faktur}")->assertStatus(200)->assertJsonCount(1, 'data.pembayaran');
        $this->getJson('/api/faktur/outstanding')->assertStatus(403);
        $this->hapus($faktur, $idPembayaran)->assertStatus(403);

        $this->actingAsRole('KEUANGAN');
        $this->hapus($faktur, $idPembayaran)->assertStatus(200);
    }

    public function test_pembayaran_invoice_perusahaan_lain_404(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = $this->buatFaktur(5000000);
        $res = $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(201);
        $idPembayaran = $res->json('data.pembayaran.0.id_pembayaran_faktur');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $penggunaLain = Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idLain, 'kode_peran' => 'SUPERADMIN',
            'username' => 'lain_' . Str::random(6), 'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('x'), 'aktif' => 1,
        ]);
        Sanctum::actingAs($penggunaLain, ['*']);

        $this->bayar($faktur, ['nominal' => 1000000])->assertStatus(404);
        $this->hapus($faktur, $idPembayaran)->assertStatus(404);
        $this->getJson('/api/faktur/outstanding')->assertStatus(200)->assertJsonPath('data.ringkasan.jumlah_invoice', 0);
        $this->assertSame(1, DB::table('pembayaran_faktur')->whereNull('dihapus_pada')->count());
    }

    public function test_daftar_invoice_memuat_terbayar_dan_sisa(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $faktur = $this->buatFaktur(2000000);
        $this->bayar($faktur, ['nominal' => 500000])->assertStatus(201);

        $baris = collect($this->getJson('/api/faktur?limit=50')->assertStatus(200)->json('data'))->firstWhere('id_faktur', $faktur->id_faktur);
        $this->assertSame(500000.0, (float) $baris['terbayar']);
        $this->assertSame(1500000.0, (float) $baris['sisa']);
    }

    public function test_laporan_outstanding_mengelompokkan_umur_piutang(): void
    {
        $this->actingAsRole('KEUANGAN');
        $alfa = $this->makeKlien('PT Alfa');
        $beta = $this->makeKlien('PT Beta');
        $belumJatuhTempo = $this->buatFaktur(1000000, 'terkirim', now()->addDays(10)->toDateString(), $alfa);
        $terlambat15 = $this->buatFaktur(2000000, 'terkirim', now()->subDays(15)->toDateString(), $alfa);
        $terlambat70 = $this->buatFaktur(3000000, 'terkirim', now()->subDays(70)->toDateString(), $beta);
        $segera = $this->buatFaktur(400000, 'terkirim', now()->addDays(3)->toDateString(), $beta);
        $tanpaJatuhTempo = $this->buatFaktur(100000, 'terkirim', null, $beta);
        $lunas = $this->buatFaktur(700000, 'terkirim', now()->subDays(5)->toDateString(), $alfa);
        $this->buatFaktur(900000, 'draft', now()->subDays(5)->toDateString(), $alfa);
        $this->buatFaktur(900000, 'batal', now()->subDays(5)->toDateString(), $alfa);

        $this->bayar($terlambat15, ['nominal' => 500000])->assertStatus(201);
        $this->bayar($lunas, ['nominal' => 700000])->assertStatus(201)->assertJsonPath('data.status', 'lunas');

        $json = $this->getJson('/api/faktur/outstanding?limit=50')->assertStatus(200)->json('data');
        $r = $json['ringkasan'];
        $this->assertSame(5, $r['jumlah_invoice']);
        $this->assertEquals(6500000.0, $r['total_tagihan']);
        $this->assertEquals(500000.0, $r['total_terbayar']);
        $this->assertEquals(6000000.0, $r['total_outstanding']);
        $this->assertSame(2, $r['lewat_jatuh_tempo']['jumlah']);
        $this->assertEquals(4500000.0, $r['lewat_jatuh_tempo']['nominal']);
        $this->assertSame(1, $r['jatuh_tempo_7_hari']['jumlah']);
        $this->assertEquals(400000.0, $r['jatuh_tempo_7_hari']['nominal']);
        $this->assertEquals(1200000.0, $r['diterima_bulan_ini']);
        $this->assertSame(3, $r['aging']['belum_jatuh_tempo']['jumlah']);
        $this->assertEquals(1500000.0, $r['aging']['belum_jatuh_tempo']['nominal']);
        $this->assertEquals(1500000.0, $r['aging']['hari_1_30']['nominal']);
        $this->assertSame(0, $r['aging']['hari_31_60']['jumlah']);
        $this->assertEquals(3000000.0, $r['aging']['di_atas_60']['nominal']);

        $this->assertSame(['PT Beta', 'PT Alfa'], array_column($json['per_klien'], 'nama_klien'));
        $this->assertEquals(3500000.0, $json['per_klien'][0]['outstanding']);
        $this->assertEquals(3000000.0, $json['per_klien'][0]['terlambat']);
        $this->assertEquals(2500000.0, $json['per_klien'][1]['outstanding']);
        $this->assertEquals(1500000.0, $json['per_klien'][1]['terlambat']);

        $urutan = array_column($json['data'], 'id_faktur');
        $this->assertSame([$terlambat70->id_faktur, $terlambat15->id_faktur, $segera->id_faktur, $belumJatuhTempo->id_faktur, $tanpaJatuhTempo->id_faktur], $urutan);
        $this->assertSame(70, $json['data'][0]['hari_terlambat']);
        $this->assertSame('di_atas_60', $json['data'][0]['kelompok']);
        $this->assertEquals(1500000.0, $json['data'][1]['sisa']);

        $terlambat = $this->getJson('/api/faktur/outstanding?kelompok=terlambat')->assertStatus(200)->json('data');
        $this->assertSame(2, $terlambat['meta']['total']);
        $this->assertSame(5, $terlambat['ringkasan']['jumlah_invoice']);
        $this->assertSame(1, $this->getJson('/api/faktur/outstanding?kelompok=di_atas_60')->json('data.meta.total'));
        $this->assertSame(2, $this->getJson("/api/faktur/outstanding?id_klien={$alfa}")->json('data.meta.total'));
        $this->assertSame(3, $this->getJson('/api/faktur/outstanding?search=beta')->json('data.meta.total'));

        $halaman = $this->getJson('/api/faktur/outstanding?limit=2&page=3')->assertStatus(200)->json('data');
        $this->assertSame(3, $halaman['meta']['totalPages']);
        $this->assertCount(1, $halaman['data']);
    }

    public function test_batas_kelompok_umur_piutang(): void
    {
        $this->actingAsRole('KEUANGAN');
        $harapan = [0 => 'belum_jatuh_tempo', 1 => 'hari_1_30', 30 => 'hari_1_30', 31 => 'hari_31_60', 60 => 'hari_31_60', 61 => 'di_atas_60'];
        $peta = [];
        foreach (array_keys($harapan) as $hari) {
            $peta[$this->buatFaktur(100000, 'terkirim', now()->subDays($hari)->toDateString())->id_faktur] = $hari;
        }

        $baris = $this->getJson('/api/faktur/outstanding?limit=50')->assertStatus(200)->json('data.data');
        $this->assertCount(count($harapan), $baris);
        foreach ($baris as $b) {
            $hari = $peta[$b['id_faktur']];
            $this->assertSame($hari, $b['hari_terlambat']);
            $this->assertSame($harapan[$hari], $b['kelompok'], "Hari ke-{$hari}");
        }
    }

    public function test_export_outstanding_excel(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->buatFaktur(1000000, 'terkirim', now()->subDays(3)->toDateString());

        $res = $this->get('/api/faktur/outstanding/export/excel');
        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
    }
}
