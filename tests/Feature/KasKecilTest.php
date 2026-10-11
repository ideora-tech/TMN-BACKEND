<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use App\Modules\ArusKas\ArusKasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KasKecilTest extends TestCase
{
    use RefreshDatabase;

    private function buatPengguna(string $kodePeran = 'SUPERADMIN', string $idPerusahaan = self::PERUSAHAAN_ID): Pengguna
    {
        $this->ensurePerusahaan();

        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => $idPerusahaan, 'kode_peran' => $kodePeran,
            'username' => 'user_' . Str::random(8), 'email' => Str::random(8) . '@test.id',
            'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function aturApproval(?string $idApprover, int $aktif = 1): void
    {
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengisian_kas_kecil', 'nama' => 'Pengisian Kas Kecil', 'mode_resolusi' => 'pinned',
            'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        if ($idApprover !== null) {
            DB::table('approval_config_approver')->insert([
                'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType,
                'tipe' => 'pengguna', 'id_pengguna' => $idApprover, 'dibuat_pada' => now(),
            ]);
        }
    }

    private function lewatiApproval(): void
    {
        $this->ensurePerusahaan();
        $sudahAda = DB::table('approval_event_type')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)->where('kode', 'pengisian_kas_kecil')->exists();
        if (!$sudahAda) {
            $this->aturApproval(null, 0);
        }
    }

    private function ajukanPengisian(float $nominal = 2000000, array $override = []): string
    {
        $res = $this->postJson('/api/kas-kecil/pengisian', array_merge([
            'tanggal' => now()->toDateString(), 'nominal' => $nominal, 'keterangan' => 'Isi ulang kas kecil kantor',
        ], $override));
        $res->assertStatus(201);

        return (string) $res->json('data.id_pengisian');
    }

    private function idPengajuan(string $idPengisian): string
    {
        return (string) DB::table('kas_kecil_pengisian')->where('id_pengisian', $idPengisian)->value('id_pengajuan');
    }

    private function transfer(string $idPengisian, ?string $tanggal = null): void
    {
        $idPengajuan = $this->idPengajuan($idPengisian);

        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');
        Storage::fake('public');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => $tanggal ?? now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);
    }

    private function isiKas(float $nominal = 2000000, ?string $tanggal = null): string
    {
        $this->lewatiApproval();
        $id = $this->ajukanPengisian($nominal);
        $this->transfer($id, $tanggal);

        return $id;
    }

    private function catatPengeluaran(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/kas-kecil/transaksi', array_merge([
            'tanggal' => now()->toDateString(), 'kategori' => 'atk', 'nominal' => 150000,
            'penerima' => 'Toko Sumber Jaya', 'keterangan' => 'Kertas HVS dan tinta printer',
        ], $override));
    }

    private function saldo(): float
    {
        return (float) $this->getJson('/api/kas-kecil/ringkasan')->assertStatus(200)->json('data.saldo');
    }

    public function test_pengisian_membuat_pengajuan_pengeluaran_dan_belum_menambah_saldo(): void
    {
        $this->lewatiApproval();
        $this->actingAsRole('KEUANGAN');
        DB::table('perusahaan')->where('id_perusahaan', self::PERUSAHAAN_ID)->update([
            'nama_bank' => 'BCA', 'nomor_rekening' => '1234567890', 'atas_nama_rekening' => 'PT TMN Transport',
        ]);

        $res = $this->postJson('/api/kas-kecil/pengisian', [
            'tanggal' => now()->toDateString(), 'nominal' => 2000000, 'keterangan' => 'Isi ulang kas kecil kantor',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'menunggu_transfer')
            ->assertJsonPath('data.nominal', 2000000)
            ->assertJsonPath('data.nama_bank', 'BCA')
            ->assertJsonPath('data.nomor_rekening', '1234567890')
            ->assertJsonPath('data.bisa_diubah', false);
        $this->assertMatchesRegularExpression('/^PKK-\d{6}-0001$/', $res->json('data.nomor_pengisian'));

        $pengajuan = DB::table('pengajuan_pengeluaran')->where('id_pengisian_kas_kecil', $res->json('data.id_pengisian'))->first();
        $this->assertSame('pengisian_kas_kecil', $pengajuan->kategori);
        $this->assertSame('Kas Kecil', $pengajuan->penerima);
        $this->assertEquals(2000000, (float) $pengajuan->nominal);
        $this->assertSame('disetujui', $pengajuan->status);

        $this->assertEquals(0, $this->saldo());
        $this->getJson('/api/kas-kecil/ringkasan')
            ->assertJsonPath('data.pengisian_diproses.jumlah', 1)
            ->assertJsonPath('data.pengisian_diproses.nominal', 2000000)
            ->assertJsonPath('data.rekening_sumber.nama_bank', 'BCA');
    }

    public function test_alur_approval_direktur_sampai_transfer_menjadi_penerimaan_kas_kecil_dan_pengeluaran_rekening(): void
    {
        $direktur = $this->buatPengguna('MANAGER');
        $this->aturApproval((string) $direktur->id_pengguna);
        $keuangan = $this->actingAsRole('KEUANGAN');
        $hariIni  = now()->toDateString();

        $id = $this->ajukanPengisian(2000000);
        $idPengajuan = $this->idPengajuan($id);

        $this->getJson("/api/kas-kecil/pengisian/{$id}")
            ->assertJsonPath('data.status', 'menunggu_approval')
            ->assertJsonPath('data.bisa_diubah', true);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(409);

        Sanctum::actingAs($direktur, ['*']);
        $menunggu = $this->getJson('/api/approval-pengajuan/menunggu-saya')->assertStatus(200)->json('data');
        $this->assertCount(1, $menunggu);
        $this->assertSame('Pengisian Kas Kecil', $menunggu[0]['nama_event_type']);
        $rincian = $this->getJson("/api/approval-pengajuan/{$menunggu[0]['id_approval']}/rincian")->assertStatus(200);
        $label = array_column($rincian->json('data.rincian.info'), 'value', 'label');
        $this->assertEquals(0, $label['Saldo Kas Kecil Saat Ini']);
        $this->assertEquals(2000000, $label['Saldo Setelah Pengisian']);
        $this->patchJson("/api/approval-pengajuan/{$menunggu[0]['id_approval']}/keputusan", ['keputusan' => 'setuju'])->assertStatus(200);

        Sanctum::actingAs($keuangan, ['*']);
        $this->getJson("/api/kas-kecil/pengisian/{$id}")->assertJsonPath('data.status', 'menunggu_transfer');
        $this->assertEquals(0, $this->saldo());

        $this->transfer($id, $hariIni);

        $this->getJson("/api/kas-kecil/pengisian/{$id}")
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.tanggal_transfer', $hariIni)
            ->assertJsonPath('data.bisa_diubah', false);
        $this->assertNotNull($this->getJson("/api/kas-kecil/pengisian/{$id}")->json('data.url_bukti_transfer'));
        $this->assertEquals(2000000, $this->saldo());

        $buku = $this->getJson("/api/kas-kecil/buku?dari={$hariIni}&sampai={$hariIni}")->assertStatus(200);
        $buku->assertJsonPath('data.total_masuk', 2000000)
            ->assertJsonPath('data.saldo_akhir', 2000000)
            ->assertJsonPath('data.data.0.jenis', 'pengisian')
            ->assertJsonPath('data.data.0.arah', 'masuk')
            ->assertJsonPath('data.data.0.saldo', 2000000)
            ->assertJsonPath('data.data.0.bisa_diubah', false);

        $rekap = $this->getJson("/api/arus-kas?dari={$hariIni}&sampai={$hariIni}")->assertStatus(200);
        $baris = collect($rekap->json('data.transaksi'))->where('kategori', 'pengisian_kas_kecil')->values();
        $this->assertCount(1, $baris);
        $this->assertSame('keluar', $baris[0]['arah']);
        $this->assertEquals(2000000, $baris[0]['nominal']);

        $psak = app(ArusKasService::class)->laporanPsak(self::PERUSAHAAN_ID, $hariIni, $hariIni);
        $kasKecil = collect($psak['kelompok'][0]['baris'])->firstWhere('label', 'Pengisian kas kecil');
        $this->assertEquals(2000000, $kasKecil['nominal']);

        $this->getJson("/api/kas-kecil/pengisian/{$id}/riwayat")
            ->assertStatus(200)
            ->assertJsonPath('data.kategori', 'pengisian_kas_kecil')
            ->assertJsonPath('data.status', 'ditransfer');
        $this->getJson("/api/arus-kas/pengajuan/{$idPengajuan}/rincian-sumber")
            ->assertStatus(200)
            ->assertJsonPath('data.tipe', 'pengisian_kas_kecil')
            ->assertJsonPath('data.data.id_pengisian', $id);
    }

    public function test_pengisian_selalu_lewat_approval_walau_di_bawah_batas_approval_keuangan(): void
    {
        $direktur = $this->buatPengguna('MANAGER');
        $this->aturApproval((string) $direktur->id_pengguna);
        $this->actingAsRole('KEUANGAN');
        app(ArusKasService::class)->setBatasApproval(self::PERUSAHAAN_ID, 999999999);

        $id = $this->ajukanPengisian(500000);

        $this->getJson("/api/kas-kecil/pengisian/{$id}")->assertJsonPath('data.status', 'menunggu_approval');
        $this->assertSame('menunggu_approval', DB::table('pengajuan_pengeluaran')->where('id_pengisian_kas_kecil', $id)->value('status'));
    }

    public function test_pengisian_ditolak_bila_approver_belum_diatur(): void
    {
        $this->aturApproval(null);
        $this->actingAsRole('KEUANGAN');

        $res = $this->postJson('/api/kas-kecil/pengisian', [
            'tanggal' => now()->toDateString(), 'nominal' => 1000000, 'keterangan' => 'Isi ulang',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Approver Pengisian Kas Kecil belum diatur', (string) $res->json('message'));
        $this->assertSame(0, DB::table('kas_kecil_pengisian')->count());
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
    }

    public function test_pengisian_ditolak_bila_jenis_approval_belum_dibuat_walau_approval_umum_aktif(): void
    {
        $approverUmum = $this->buatPengguna('MANAGER');
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'pengajuan_pengeluaran',
            'nama' => 'Pengajuan Pengeluaran', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna',
            'id_pengguna' => $approverUmum->id_pengguna, 'dibuat_pada' => now(),
        ]);
        $this->actingAsRole('KEUANGAN');

        $res = $this->postJson('/api/kas-kecil/pengisian', [
            'tanggal' => now()->toDateString(), 'nominal' => 1000000, 'keterangan' => 'Isi ulang',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Approver Pengisian Kas Kecil belum diatur', (string) $res->json('message'));
        $this->assertSame(0, DB::table('pengajuan_pengeluaran')->count());
        $this->assertSame(0, DB::table('approval_pengajuan')->count());
    }

    public function test_jenis_approval_yang_sengaja_dinonaktifkan_melewati_approval(): void
    {
        $direktur = $this->buatPengguna('MANAGER');
        $this->aturApproval((string) $direktur->id_pengguna, 0);
        $this->actingAsRole('KEUANGAN');

        $id = $this->ajukanPengisian(800000);

        $this->getJson("/api/kas-kecil/pengisian/{$id}")->assertJsonPath('data.status', 'menunggu_transfer');
        $this->assertSame(0, DB::table('approval_pengajuan')->count());
    }

    public function test_ubah_saat_menunggu_approval_membatalkan_approval_lama_dan_meminta_persetujuan_nominal_baru(): void
    {
        $direktur = $this->buatPengguna('MANAGER');
        $this->aturApproval((string) $direktur->id_pengguna);
        $keuangan = $this->actingAsRole('KEUANGAN');
        $id = $this->ajukanPengisian(1000000);
        $idPengajuan = $this->idPengajuan($id);

        $this->putJson("/api/kas-kecil/pengisian/{$id}", [
            'tanggal' => now()->toDateString(), 'nominal' => 3000000, 'keterangan' => 'Dinaikkan',
        ])->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');

        $approval = DB::table('approval_pengajuan')->where('id_referensi', $idPengajuan)->get();
        $this->assertSame(['dibatalkan', 'menunggu'], $approval->pluck('status')->sort()->values()->all());
        $this->assertEquals(3000000, (float) $approval->firstWhere('status', 'menunggu')->nominal);

        Sanctum::actingAs($direktur, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])->assertStatus(200);

        Sanctum::actingAs($keuangan, ['*']);
        $this->transfer($id);
        $this->assertEquals(3000000, $this->saldo());

        $rincian = app(\App\Modules\Approval\Contracts\RincianReferensiRepositoryInterface::class)
            ->rincian('pengisian_kas_kecil', $idPengajuan, self::PERUSAHAAN_ID);
        $label = array_column($rincian['info'], 'value', 'label');
        $this->assertEquals(3000000, $label['Saldo Kas Kecil Saat Ini']);
        $this->assertArrayNotHasKey('Saldo Setelah Pengisian', $label);
    }

    public function test_tanggal_transfer_pengisian_tidak_boleh_masa_depan_atau_sebelum_tanggal_pengisian(): void
    {
        $this->lewatiApproval();
        $this->actingAsRole('KEUANGAN');
        $id = $this->ajukanPengisian(1000000);
        $idPengajuan = $this->idPengajuan($id);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        Storage::fake('public');

        foreach ([now()->addDay()->toDateString(), now()->subDay()->toDateString()] as $tanggalSalah) {
            $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
                'tanggal_transfer' => $tanggalSalah,
                'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
            ], ['Accept' => 'application/json'])->assertStatus(422);
        }

        $this->assertSame('siap_transfer', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('status'));
        $this->assertSame(0, DB::table('kas_kecil_transaksi')->count());
        $this->assertEquals(0, $this->saldo());

        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertStatus(200);
        $this->assertEquals(1000000, $this->saldo());
        $this->assertSame(now()->toDateString(), substr((string) DB::table('kas_kecil_transaksi')->value('tanggal'), 0, 10));
    }

    public function test_pengisian_yang_ditolak_keuangan_setelah_approval_bisa_diajukan_ulang(): void
    {
        $this->lewatiApproval();
        $this->actingAsRole('SUPERADMIN');
        $id = $this->ajukanPengisian(1500000);
        $idPengajuan = $this->idPengajuan($id);

        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/tolak", ['alasan' => 'Saldo rekening belum cukup'])->assertStatus(200);
        $this->getJson("/api/kas-kecil/pengisian/{$id}")
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.bisa_diubah', true);
        $this->assertSame(0, DB::table('kas_kecil_transaksi')->count());

        $this->putJson("/api/kas-kecil/pengisian/{$id}", [
            'tanggal' => now()->toDateString(), 'nominal' => 500000, 'keterangan' => 'Dikecilkan',
        ])->assertStatus(200)->assertJsonPath('data.status', 'menunggu_transfer');
        $this->transfer($id);
        $this->assertEquals(500000, $this->saldo());
    }

    public function test_pengisian_ditolak_approver_bisa_diperbaiki_dan_diajukan_ulang(): void
    {
        $direktur = $this->buatPengguna('MANAGER');
        $this->aturApproval((string) $direktur->id_pengguna);
        $keuangan = $this->actingAsRole('KEUANGAN');
        $id = $this->ajukanPengisian(5000000);
        $idPengajuan = $this->idPengajuan($id);

        Sanctum::actingAs($direktur, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'tolak', 'catatan' => 'Terlalu besar'])->assertStatus(200);

        Sanctum::actingAs($keuangan, ['*']);
        $this->getJson("/api/kas-kecil/pengisian/{$id}")
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan_ditolak', 'Terlalu besar')
            ->assertJsonPath('data.bisa_diubah', true);

        $this->putJson("/api/kas-kecil/pengisian/{$id}", [
            'tanggal' => now()->toDateString(), 'nominal' => 2500000, 'keterangan' => 'Isi ulang — direvisi',
        ])->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval')->assertJsonPath('data.nominal', 2500000);

        $this->assertEquals(2500000, (float) DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('nominal'));

        Sanctum::actingAs($direktur, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])->assertStatus(200);
        Sanctum::actingAs($keuangan, ['*']);
        $this->transfer($id);
        $this->assertEquals(2500000, $this->saldo());
    }

    public function test_pengisian_tidak_bisa_diubah_atau_dihapus_setelah_disetujui_dan_pengajuannya_terkunci_di_arus_kas(): void
    {
        $direktur = $this->buatPengguna('MANAGER');
        $this->aturApproval((string) $direktur->id_pengguna);
        $this->actingAsRole('SUPERADMIN');
        $id = $this->ajukanPengisian();
        $idPengajuan = $this->idPengajuan($id);

        $this->putJson("/api/arus-kas/pengajuan/{$idPengajuan}", [
            'kategori' => 'lainnya', 'nominal' => 1, 'tanggal_pengajuan' => now()->toDateString(), 'penerima' => 'X',
        ])->assertStatus(422);
        $this->deleteJson("/api/arus-kas/pengajuan/{$idPengajuan}")->assertStatus(422);

        $idLain = $this->ajukanPengisian(700000);
        $this->deleteJson("/api/kas-kecil/pengisian/{$idLain}")->assertStatus(200);
        $this->assertNotNull(DB::table('kas_kecil_pengisian')->where('id_pengisian', $idLain)->value('dihapus_pada'));
        $this->assertNotNull(DB::table('pengajuan_pengeluaran')->where('id_pengisian_kas_kecil', $idLain)->value('dihapus_pada'));

        Sanctum::actingAs($direktur, ['*']);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])->assertStatus(200);

        $this->actingAsRole('SUPERADMIN');
        $this->putJson("/api/kas-kecil/pengisian/{$id}", [
            'tanggal' => now()->toDateString(), 'nominal' => 9000000, 'keterangan' => 'Ubah setelah disetujui',
        ])->assertStatus(409);
        $this->deleteJson("/api/kas-kecil/pengisian/{$id}")->assertStatus(409);
    }

    public function test_pengeluaran_mengurangi_saldo_dan_ditolak_bila_melebihi_saldo(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->isiKas(500000);

        $res = $this->catatPengeluaran(['nominal' => 150000]);
        $res->assertStatus(201)
            ->assertJsonPath('data.arah', 'keluar')
            ->assertJsonPath('data.jenis', 'pengeluaran')
            ->assertJsonPath('data.label_kategori', 'ATK & Perlengkapan Kantor')
            ->assertJsonPath('data.bisa_diubah', true);
        $this->assertMatchesRegularExpression('/^KK-\d{6}-0002$/', $res->json('data.nomor_transaksi'));
        $this->assertEquals(350000, $this->saldo());

        $gagal = $this->catatPengeluaran(['nominal' => 350001]);
        $gagal->assertStatus(422);
        $this->assertStringContainsString('Saldo kas kecil tidak cukup', (string) $gagal->json('errors.nominal.0'));
        $this->assertEquals(350000, $this->saldo());

        $this->catatPengeluaran(['nominal' => 350000])->assertStatus(201);
        $this->assertEquals(0, $this->saldo());
    }

    public function test_pengeluaran_bertanggal_sebelum_kas_terisi_ditolak(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->isiKas(500000, now()->toDateString());

        $this->catatPengeluaran(['tanggal' => now()->subDay()->toDateString(), 'nominal' => 100000])->assertStatus(422);
        $this->catatPengeluaran(['tanggal' => now()->toDateString(), 'nominal' => 100000])->assertStatus(201);
    }

    public function test_ubah_dan_hapus_pengeluaran_menjaga_saldo(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->isiKas(500000);
        $id = (string) $this->catatPengeluaran(['nominal' => 200000])->json('data.id_transaksi');

        $this->putJson("/api/kas-kecil/transaksi/{$id}", [
            'tanggal' => now()->toDateString(), 'kategori' => 'konsumsi', 'nominal' => 500001, 'keterangan' => 'Makan siang rapat',
        ])->assertStatus(422);
        $this->assertEquals(300000, $this->saldo());

        $this->putJson("/api/kas-kecil/transaksi/{$id}", [
            'tanggal' => now()->toDateString(), 'kategori' => 'konsumsi', 'nominal' => 450000, 'keterangan' => 'Makan siang rapat',
        ])->assertStatus(200)->assertJsonPath('data.kategori', 'konsumsi')->assertJsonPath('data.nominal', 450000);
        $this->assertEquals(50000, $this->saldo());

        $this->putJson("/api/kas-kecil/transaksi/{$id}", [
            'tanggal' => now()->subDay()->toDateString(), 'kategori' => 'konsumsi', 'nominal' => 100000, 'keterangan' => 'Dimundurkan',
        ])->assertStatus(422);
        $this->assertSame(now()->toDateString(), substr((string) DB::table('kas_kecil_transaksi')->where('id_transaksi', $id)->value('tanggal'), 0, 10));
        $this->assertEquals(50000, $this->saldo());

        $this->deleteJson("/api/kas-kecil/transaksi/{$id}")->assertStatus(200);
        $this->assertEquals(500000, $this->saldo());

        $idMasuk = (string) DB::table('kas_kecil_transaksi')->where('jenis', 'pengisian')->value('id_transaksi');
        $this->deleteJson("/api/kas-kecil/transaksi/{$idMasuk}")->assertStatus(409);
        $this->putJson("/api/kas-kecil/transaksi/{$idMasuk}", [
            'tanggal' => now()->toDateString(), 'kategori' => 'atk', 'nominal' => 1, 'keterangan' => 'x',
        ])->assertStatus(409);
    }

    public function test_bukti_pengeluaran_tersimpan_dan_bisa_dihapus(): void
    {
        Storage::fake('public');
        $this->actingAsRole('KEUANGAN');
        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => now()->toDateString(), 'nominal' => 300000])->assertStatus(201);

        $res = $this->post('/api/kas-kecil/transaksi', [
            'tanggal' => now()->toDateString(), 'kategori' => 'transportasi', 'nominal' => 25000,
            'keterangan' => 'Parkir dan tol', 'bukti' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json']);
        $res->assertStatus(201);
        $this->assertNotNull($res->json('data.url_bukti'));
        $id = (string) $res->json('data.id_transaksi');

        $this->post("/api/kas-kecil/transaksi/{$id}", [
            '_method' => 'PUT', 'tanggal' => now()->toDateString(), 'kategori' => 'transportasi', 'nominal' => 25000,
            'keterangan' => 'Parkir dan tol', 'hapus_bukti' => '1',
        ], ['Accept' => 'application/json'])->assertStatus(200)->assertJsonPath('data.url_bukti', null);
    }

    public function test_saldo_awal_hanya_sekali_dan_tidak_bisa_dihapus_bila_membuat_saldo_minus(): void
    {
        $this->actingAsRole('KEUANGAN');
        $hariIni = now()->toDateString();

        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => $hariIni, 'nominal' => 400000])
            ->assertStatus(201)->assertJsonPath('data.jenis', 'saldo_awal');
        $this->assertEquals(400000, $this->saldo());
        $this->getJson('/api/kas-kecil/ringkasan')->assertJsonPath('data.ada_saldo_awal', true)->assertJsonPath('data.boleh_saldo_awal', true);

        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => $hariIni, 'nominal' => 1])->assertStatus(409);
        $this->getJson('/api/kas-kecil/ringkasan')->assertJsonPath('data.ada_transaksi', true);

        $idKeluar = (string) $this->catatPengeluaran(['nominal' => 100000])->assertStatus(201)->json('data.id_transaksi');
        $this->deleteJson('/api/kas-kecil/saldo-awal')->assertStatus(409);
        $this->assertEquals(300000, $this->saldo());

        $this->deleteJson("/api/kas-kecil/transaksi/{$idKeluar}")->assertStatus(200);
        $this->deleteJson('/api/kas-kecil/saldo-awal')->assertStatus(200);
        $this->assertEquals(0, $this->saldo());
    }

    public function test_saldo_awal_ditolak_bila_buku_kas_sudah_berjalan(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->isiKas(500000);

        $res = $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => now()->subDays(3)->toDateString(), 'nominal' => 9000000]);

        $res->assertStatus(409);
        $this->assertStringContainsString('sebelum ada transaksi', (string) $res->json('message'));
        $this->assertEquals(500000, $this->saldo());
        $this->getJson('/api/kas-kecil/ringkasan')->assertJsonPath('data.ada_saldo_awal', false)->assertJsonPath('data.ada_transaksi', true);
    }

    public function test_izin_manager_hanya_lihat_dan_saldo_awal_khusus_keuangan(): void
    {
        $this->actingAsRole('KEUANGAN');
        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => now()->toDateString(), 'nominal' => 400000])->assertStatus(201);

        $this->actingAsRole('MANAGER');
        $this->getJson('/api/kas-kecil/ringkasan')->assertStatus(200)->assertJsonPath('data.boleh_saldo_awal', false);
        $this->getJson('/api/kas-kecil/buku')->assertStatus(200);
        $this->getJson('/api/kas-kecil/pengisian')->assertStatus(200);
        $this->catatPengeluaran()->assertStatus(403);
        $this->postJson('/api/kas-kecil/pengisian', ['tanggal' => now()->toDateString(), 'nominal' => 1000, 'keterangan' => 'x'])->assertStatus(403);
        $this->deleteJson('/api/kas-kecil/saldo-awal')->assertStatus(403);

        $this->actingAsRole('SALES');
        $this->getJson('/api/kas-kecil/ringkasan')->assertStatus(403);
    }

    public function test_data_perusahaan_lain_tidak_terlihat(): void
    {
        $this->actingAsRole('KEUANGAN');
        $idPengisian = $this->isiKas(900000);
        $idTransaksi = (string) $this->catatPengeluaran(['nominal' => 50000])->json('data.id_transaksi');

        $idLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idLain, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        Sanctum::actingAs($this->buatPengguna('KEUANGAN', $idLain), ['*']);

        $this->assertEquals(0, $this->saldo());
        $this->getJson('/api/kas-kecil/pengisian')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson('/api/kas-kecil/buku')->assertStatus(200)->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/kas-kecil/pengisian/{$idPengisian}")->assertStatus(404);
        $this->getJson("/api/kas-kecil/transaksi/{$idTransaksi}")->assertStatus(404);
        $this->deleteJson("/api/kas-kecil/transaksi/{$idTransaksi}")->assertStatus(404);
        $this->putJson("/api/kas-kecil/pengisian/{$idPengisian}", [
            'tanggal' => now()->toDateString(), 'nominal' => 1, 'keterangan' => 'x',
        ])->assertStatus(404);
        $this->deleteJson("/api/kas-kecil/pengisian/{$idPengisian}")->assertStatus(404);
        $this->getJson("/api/kas-kecil/pengisian/{$idPengisian}/riwayat")->assertStatus(404);
        $this->putJson("/api/kas-kecil/transaksi/{$idTransaksi}", [
            'tanggal' => now()->toDateString(), 'kategori' => 'atk', 'nominal' => 1, 'keterangan' => 'x',
        ])->assertStatus(404);
        $this->assertEquals(50000, (float) DB::table('kas_kecil_transaksi')->where('id_transaksi', $idTransaksi)->value('nominal'));
    }

    public function test_buku_kas_menghitung_saldo_awal_periode_saldo_berjalan_filter_dan_export(): void
    {
        $this->actingAsRole('KEUANGAN');
        $kemarin = now()->subDay()->toDateString();
        $hariIni = now()->toDateString();

        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => $kemarin, 'nominal' => 1000000])->assertStatus(201);
        $this->catatPengeluaran(['tanggal' => $kemarin, 'nominal' => 200000, 'kategori' => 'bbm', 'keterangan' => 'Solar genset'])->assertStatus(201);
        $this->catatPengeluaran(['tanggal' => $hariIni, 'nominal' => 100000, 'kategori' => 'atk', 'keterangan' => 'Kertas'])->assertStatus(201);
        $this->catatPengeluaran(['tanggal' => $hariIni, 'nominal' => 50000, 'kategori' => 'konsumsi', 'keterangan' => 'Air galon'])->assertStatus(201);

        $buku = $this->getJson("/api/kas-kecil/buku?dari={$hariIni}&sampai={$hariIni}")->assertStatus(200);
        $buku->assertJsonPath('data.saldo_awal', 800000)
            ->assertJsonPath('data.total_masuk', 0)
            ->assertJsonPath('data.total_keluar', 150000)
            ->assertJsonPath('data.saldo_akhir', 650000)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.data.0.keterangan', 'Air galon')
            ->assertJsonPath('data.data.0.saldo', 650000)
            ->assertJsonPath('data.data.1.saldo', 700000);

        $this->getJson("/api/kas-kecil/buku?dari={$kemarin}&sampai={$hariIni}&kategori=bbm")
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.saldo', 800000)
            ->assertJsonPath('data.total_keluar', 350000)
            ->assertJsonPath('data.tersaring.jumlah', 1)
            ->assertJsonPath('data.tersaring.keluar', 200000)
            ->assertJsonPath('data.tersaring.masuk', 0);
        $this->getJson('/api/kas-kecil/buku?dari=abc')->assertStatus(422);
        $this->getJson("/api/kas-kecil/buku?dari={$kemarin}&sampai={$hariIni}&arah=samping")->assertStatus(422);
        $this->get('/api/kas-kecil/buku/export/excel?sampai=31-12-2026', ['Accept' => 'application/json'])->assertStatus(422);
        $this->getJson("/api/kas-kecil/buku?dari={$kemarin}&sampai={$hariIni}&search=galon")->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/kas-kecil/buku?dari={$kemarin}&sampai={$hariIni}&arah=masuk")->assertJsonPath('data.meta.total', 1);
        $this->getJson("/api/kas-kecil/buku?dari={$hariIni}&sampai={$kemarin}")->assertStatus(422);

        $export = $this->get("/api/kas-kecil/buku/export/excel?dari={$kemarin}&sampai={$hariIni}");
        $export->assertStatus(200);
        $this->assertStringContainsString('buku-kas-kecil-', (string) $export->headers->get('content-disposition'));
    }

    public function test_validasi_form(): void
    {
        $this->actingAsRole('KEUANGAN');

        $this->postJson('/api/kas-kecil/pengisian', ['tanggal' => now()->addDay()->toDateString(), 'nominal' => 0, 'keterangan' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['tanggal', 'nominal', 'keterangan']);
        $this->postJson('/api/kas-kecil/transaksi', ['tanggal' => now()->toDateString(), 'kategori' => 'tidak_ada', 'nominal' => 0, 'keterangan' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['kategori', 'nominal', 'keterangan']);
        $this->postJson('/api/kas-kecil/saldo-awal', ['tanggal' => 'salah', 'nominal' => -5])
            ->assertStatus(422)->assertJsonValidationErrors(['tanggal', 'nominal']);
    }
}
