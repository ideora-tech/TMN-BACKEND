<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReapprovalNominalRealisasiTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier')->insert([
            'id_supplier'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Toko Sparepart Reapproval',
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart'  => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode'          => 'SP-' . Str::random(6),
            'nama'          => $nama,
            'satuan'        => 'pcs',
            'harga_standar' => 50000,
            'stok'          => 0,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function payloadPembelian(array $override = []): array
    {
        return array_merge([
            'id_supplier'       => $this->makeSupplier(),
            'tanggal_pengajuan' => now()->toDateString(),
            'items'             => [
                ['id_sparepart' => $this->makeSparepart('Oli Mesin'), 'qty' => 2, 'harga_estimasi' => 60000],
                ['id_sparepart' => $this->makeSparepart('Filter Udara'), 'qty' => 1, 'harga_estimasi' => 80000],
            ],
        ], $override);
    }

    private function pengajuanUntukPembelian(string $idPembelian): ?object
    {
        return DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->first();
    }

    private function buatPengguna(string $username): string
    {
        $id = (string) Str::uuid();
        DB::table('pengguna')->insert([
            'id_pengguna'   => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'MANAGER',
            'username'      => $username,
            'email'         => $username . '@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function idEventTypePengajuanPengeluaran(): string
    {
        $id = DB::table('approval_event_type')
            ->where('id_perusahaan', self::PERUSAHAAN_ID)->where('kode', 'pengajuan_pengeluaran')->value('id_event_type');
        if ($id !== null) {
            return $id;
        }
        $id = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode' => 'pengajuan_pengeluaran', 'nama' => 'Pengajuan Pengeluaran',
            'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function tambahApproverPengguna(string $idPengguna): void
    {
        DB::table('approval_config_approver')->insert([
            'id_config'     => (string) Str::uuid(),
            'id_event_type' => $this->idEventTypePengajuanPengeluaran(),
            'tipe'          => 'pengguna',
            'id_pengguna'   => $idPengguna,
            'dibuat_pada'   => now(),
        ]);
    }

    private function setBatas(float $batas): void
    {
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => $batas])->assertStatus(200);
    }

    private function actingAsPengguna(string $idPengguna): void
    {
        Sanctum::actingAs(Pengguna::findOrFail($idPengguna), ['*']);
    }

    private function buatPembelian(): array
    {
        $create = $this->postJson('/api/pembelian-sparepart', $this->payloadPembelian());
        $create->assertStatus(201);
        $idPembelian = $create->json('data.id_pembelian');
        $items = $create->json('data.items');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;
        return [$idPembelian, $items, $idPengajuan];
    }

    private function setujuiSebagaiApprover(string $idPengajuan, string $idApprover): void
    {
        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);
    }

    private function realisasi(string $idPembelian, array $items, float $hargaAktual1, float $hargaAktual2): \Illuminate\Testing\TestResponse
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        return $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => $hargaAktual1],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => $hargaAktual2],
            ],
        ]);
    }

    private function approvalRows(string $idPengajuan, bool $termasukTerhapus = false): array
    {
        $q = DB::table('approval_keputusan as ak')
            ->join('approval_pengajuan as ap', 'ap.id_approval', '=', 'ak.id_approval')
            ->where('ap.id_referensi', $idPengajuan)
            ->select('ak.id_keputusan as id_approval', 'ak.id_pengguna', 'ak.status', 'ak.catatan', 'ak.waktu_aksi', 'ak.dihapus_pada', 'ak.dibuat_pada');
        if (!$termasukTerhapus) {
            $q->whereNull('ak.dihapus_pada');
        }
        return $q->orderBy('ak.dibuat_pada')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_realisasi_naik_melewati_batas_setelah_approval_memicu_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_satu');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);
        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(205000, (float) $pengajuan->nominal);
        $this->assertNull($pengajuan->disetujui_oleh);
        $this->assertNull($pengajuan->disetujui_pada);

        $aktif = $this->approvalRows($idPengajuan);
        $this->assertCount(1, $aktif);
        $this->assertSame('menunggu', $aktif[0]['status']);

        $semua = $this->approvalRows($idPengajuan, true);
        $this->assertCount(2, $semua);
        $terhapus = array_values(array_filter($semua, fn ($r) => $r['dihapus_pada'] !== null));
        $this->assertCount(1, $terhapus);
        $this->assertSame('disetujui', $terhapus[0]['status']);

        $notif = DB::table('notifikasi')
            ->where('id_pengguna', $idApprover)
            ->where('judul', 'like', '%perlu approval ulang%')
            ->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Rp 200.000', (string) $notif->isi);
        $this->assertStringContainsString('Rp 205.000', (string) $notif->isi);
    }

    public function test_auto_disetujui_lalu_naik_hingga_melewati_batas_masuk_menunggu_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_dua');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(201000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(205000, (float) $pengajuan->nominal);
        $this->assertCount(1, $this->approvalRows($idPengajuan));
    }

    public function test_naik_tapi_masih_di_bawah_batas_tetap_disetujui(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_tiga');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(500000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('disetujui', $pengajuan->status);
        $this->assertEquals(205000, (float) $pengajuan->nominal);
        $this->assertCount(0, $this->approvalRows($idPengajuan, true));
    }

    public function test_turun_setelah_disetujui_update_langsung_tanpa_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_empat');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);

        $this->realisasi($idPembelian, $items, 50000, 60000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('disetujui', $pengajuan->status);
        $this->assertEquals(160000, (float) $pengajuan->nominal);
        $rows = $this->approvalRows($idPengajuan, true);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['dihapus_pada']);
        $this->assertSame('disetujui', $rows[0]['status']);
    }

    public function test_naik_saat_menunggu_approval_reset_semua_baris_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('bod_lima');
        $idApprover2 = $this->buatPengguna('bod_enam');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);
        $this->setBatas(0);

        [$idPembelian, , $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);

        $this->actingAsRole('SUPERADMIN');
        $payload = $this->payloadPembelian();
        $payload['items'][0]['harga_estimasi'] = 70000;
        $this->putJson("/api/pembelian-sparepart/{$idPembelian}", $payload)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(220000, (float) $pengajuan->nominal);

        $aktif = $this->approvalRows($idPengajuan);
        $this->assertCount(2, $aktif);
        foreach ($aktif as $baris) {
            $this->assertSame('menunggu', $baris['status']);
        }
        $this->assertCount(4, $this->approvalRows($idPengajuan, true));
    }

    public function test_turun_saat_menunggu_approval_pertahankan_approval_yang_sudah_masuk(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover1 = $this->buatPengguna('bod_tujuh');
        $idApprover2 = $this->buatPengguna('bod_delapan');
        $this->tambahApproverPengguna($idApprover1);
        $this->tambahApproverPengguna($idApprover2);
        $this->setBatas(0);

        [$idPembelian, , $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);

        $this->actingAsRole('SUPERADMIN');
        $payload = $this->payloadPembelian();
        $payload['items'][0]['harga_estimasi'] = 50000;
        $this->putJson("/api/pembelian-sparepart/{$idPembelian}", $payload)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(180000, (float) $pengajuan->nominal);

        $aktif = $this->approvalRows($idPengajuan);
        $this->assertCount(2, $aktif);
        $statuses = array_column($aktif, 'status');
        sort($statuses);
        $this->assertSame(['disetujui', 'menunggu'], $statuses);
    }

    public function test_naik_dibawah_batas_saat_dicek_tetap_dicek(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(999999999);
        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)
            ->update(['status' => 'dicek']);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('dicek', $pengajuan->status);
        $this->assertEquals(205000, (float) $pengajuan->nominal);
    }

    public function test_naik_melewati_batas_saat_dicek_reset_ke_menunggu_approval(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_dicek_naik');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);
        $this->assertSame('disetujui', $this->pengajuanUntukPembelian($idPembelian)->status);
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)
            ->update(['status' => 'dicek', 'dicek_oleh' => $idApprover, 'dicek_pada' => now()]);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(205000, (float) $pengajuan->nominal);
        $this->assertNull($pengajuan->dicek_oleh);
        $this->assertNull($pengajuan->dicek_pada);
        $this->assertNull($pengajuan->disetujui_oleh);
        $this->assertNull($pengajuan->disetujui_pada);

        $aktif = $this->approvalRows($idPengajuan);
        $this->assertCount(1, $aktif);
        $this->assertSame('menunggu', $aktif[0]['status']);
    }

    public function test_status_ditransfer_nominal_tidak_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(999999999);
        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(200);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('ditransfer', $pengajuan->status);
        $this->assertEquals(200000, (float) $pengajuan->nominal);
    }

    public function test_naik_melewati_batas_tanpa_approver_realisasi_rollback(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(201000);
        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        $stokSebelum = (float) DB::table('sparepart')
            ->where('id_sparepart', DB::table('pembelian_sparepart_item')->where('id_item', $items[0]['id_item'])->value('id_sparepart'))
            ->value('stok');

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(422);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('siap_transfer', $pengajuan->status);
        $this->assertEquals(200000, (float) $pengajuan->nominal);

        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $rowPembelian->status);

        $stokSesudah = (float) DB::table('sparepart')
            ->where('id_sparepart', DB::table('pembelian_sparepart_item')->where('id_item', $items[0]['id_item'])->value('id_sparepart'))
            ->value('stok');
        $this->assertEquals($stokSebelum, $stokSesudah);
    }

    public function test_setelah_realisasi_naik_disetujui_ulang_transfer_membuat_pembelian_lunas_tanpa_stok_dobel(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_sembilan');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);

        $idSparepart = DB::table('pembelian_sparepart_item')->where('id_item', $items[0]['id_item'])->value('id_sparepart');
        $stokSetelahRealisasi = (float) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok');

        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('disetujui', $pengajuan->status);

        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('dibeli', $rowPembelian->status);

        $this->actingAsRole('SUPERADMIN');
        $this->patchJson("/api/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => 65000],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => 75000],
            ],
        ])->assertStatus(422);

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');
        $transfer = $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ]);
        $transfer->assertStatus(200)->assertJsonPath('data.status', 'ditransfer');

        $rowPembelianSetelahTransfer = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('lunas', $rowPembelianSetelahTransfer->status);

        $stokSetelahTransfer = (float) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok');
        $this->assertEquals($stokSetelahRealisasi, $stokSetelahTransfer);
    }

    public function test_setelah_realisasi_naik_approver_tolak_pembelian_tetap_dibeli_stok_tidak_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_sepuluh');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);

        $idSparepart = DB::table('pembelian_sparepart_item')->where('id_item', $items[0]['id_item'])->value('id_sparepart');
        $stokSebelumTolak = (float) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok');

        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/approval", [
            'keputusan' => 'tolak',
            'catatan'   => 'Kenaikan harga tidak wajar',
        ])->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('ditolak', $pengajuan->status);
        $this->assertSame('Kenaikan harga tidak wajar', $pengajuan->alasan_ditolak);

        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('dibeli', $rowPembelian->status);

        $stokSesudahTolak = (float) DB::table('sparepart')->where('id_sparepart', $idSparepart)->value('stok');
        $this->assertEquals($stokSebelumTolak, $stokSesudahTolak);
    }

    public function test_transfer_race_status_berubah_sebelum_transaksi_dikembalikan_409(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(999999999);
        [$idPembelian, , $idPengajuan] = $this->buatPembelian();
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'siap_transfer');

        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)
            ->update(['status' => 'menunggu_approval']);

        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 5, 'image/jpeg'),
        ])->assertStatus(409);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
    }

    public function test_realisasi_nominal_sama_dengan_estimasi_tidak_membuat_snapshot_baru(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_sebelas');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->assertSame('menunggu_approval', $this->pengajuanUntukPembelian($idPembelian)->status);
        $this->setujuiSebagaiApprover($idPengajuan, $idApprover);

        $sebelum = $this->approvalRows($idPengajuan, true);

        $this->realisasi($idPembelian, $items, 60000, 80000)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('disetujui', $pengajuan->status);
        $this->assertEquals(200000, (float) $pengajuan->nominal);

        $sesudah = $this->approvalRows($idPengajuan, true);
        $this->assertCount(count($sebelum), $sesudah);
        $this->assertSame($sebelum[0]['id_approval'], $sesudah[0]['id_approval']);
    }
}
