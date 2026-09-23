<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArusKasNotifikasiPengadaanTest extends TestCase
{
    use RefreshDatabase;

    private function setIzin(string $path, string $kodePeran): void
    {
        $idMenu = DB::table('menu')->where('path', $path)->value('id_menu');
        if ($idMenu === null) {
            $idMenu = (string) Str::uuid();
            DB::table('menu')->insert(['id_menu' => $idMenu, 'nama_menu' => trim($path, '/'), 'path' => $path, 'aktif' => 1, 'dibuat_pada' => now()]);
        }
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null, 'kode_peran' => $kodePeran,
            'id_menu' => $idMenu, 'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => now(),
        ]);
    }

    private function buatPengguna(string $kodePeran): Pengguna
    {
        $this->ensurePerusahaan();
        return Pengguna::create([
            'id_pengguna' => (string) Str::uuid(), 'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran' => $kodePeran, 'username' => 'u_' . Str::random(8),
            'email' => Str::random(8) . '@test.id', 'kata_sandi' => bcrypt('Password123!'), 'aktif' => 1,
        ]);
    }

    private function buatPengajuanPembelian(): array
    {
        Storage::fake('public');
        $idSupplier = (string) Str::uuid();
        DB::table('supplier')->insert(['id_supplier' => $idSupplier, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nama' => 'Toko Part', 'aktif' => 1, 'dibuat_pada' => now()]);
        $idSparepart = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $idSparepart, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'SP-' . Str::random(5),
            'nama' => 'Kampas', 'satuan' => 'pcs', 'harga_standar' => 1000, 'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        $res = $this->postJson('/api/pembelian-sparepart', [
            'id_supplier' => $idSupplier, 'tanggal_pengajuan' => now()->toDateString(),
            'items' => [['id_sparepart' => $idSparepart, 'qty' => 1, 'harga_estimasi' => 500000]],
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ]);
        $res->assertStatus(201);
        $idPembelian = $res->json('data.id_pembelian');
        $idPengajuan = (string) DB::table('pengajuan_pengeluaran')->where('id_pembelian', $idPembelian)->value('id_pengajuan');
        return [$idPembelian, $idPengajuan];
    }

    private function notifUntuk(string $idPengguna, string $tipe): int
    {
        return DB::table('notifikasi')->where('id_pengguna', $idPengguna)->where('tipe', $tipe)->count();
    }

    public function test_pengajuan_pembelian_disetujui_otomatis_menotifikasi_pemilik_menu_pembelian(): void
    {
        $this->setIzin('/pembelian-sparepart', 'PENGADAAN');
        $pengadaan = $this->buatPengguna('PENGADAAN');
        $orangLain = $this->buatPengguna('SALES');
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);

        [$idPembelian] = $this->buatPengajuanPembelian();

        $this->assertSame('disetujui_finance', DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->value('status'));
        $this->assertSame(1, $this->notifUntuk($pengadaan->id_pengguna, 'pengadaan_disetujui'));
        $this->assertSame(0, $this->notifUntuk($orangLain->id_pengguna, 'pengadaan_disetujui'));
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $pengadaan->id_pengguna, 'tipe' => 'pengadaan_disetujui',
            'referensi_tipe' => 'pembelian_sparepart', 'referensi_id' => $idPembelian, 'link' => '/pembelian-sparepart/' . $idPembelian,
        ]);
    }

    public function test_pengajuan_pembelian_ditolak_menotifikasi_pemilik_menu_pembelian(): void
    {
        $this->setIzin('/pembelian-sparepart', 'PENGADAAN');
        $pengadaan = $this->buatPengguna('PENGADAAN');
        $approver = $this->buatPengguna('MANAGER');
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 0])->assertStatus(200);
        $idEventType = (string) Str::uuid();
        DB::table('approval_event_type')->insert([
            'id_event_type' => $idEventType, 'id_perusahaan' => self::PERUSAHAAN_ID, 'kode' => 'sparepart',
            'nama' => 'Sparepart', 'mode_resolusi' => 'pinned', 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        DB::table('approval_config_approver')->insert([
            'id_config' => (string) Str::uuid(), 'id_event_type' => $idEventType, 'tipe' => 'pengguna',
            'id_pengguna' => $approver->id_pengguna, 'dibuat_pada' => now(),
        ]);

        [$idPembelian, $idPengajuan] = $this->buatPengajuanPembelian();
        $this->assertSame('menunggu_approval', DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)->value('status'));

        $idApproval = DB::table('approval_pengajuan')->where('id_referensi', $idPengajuan)->value('id_approval');
        \Laravel\Sanctum\Sanctum::actingAs($approver, ['*']);
        $this->patchJson("/api/approval-pengajuan/{$idApproval}/keputusan", ['keputusan' => 'tolak', 'catatan' => 'Harga kemahalan'])->assertStatus(200);

        $this->assertSame('ditolak', DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->value('status'));
        $this->assertSame(1, $this->notifUntuk($pengadaan->id_pengguna, 'pengadaan_ditolak'));
    }

    public function test_transfer_pengajuan_pembelian_menotifikasi_pemilik_menu_pembelian(): void
    {
        $this->setIzin('/pembelian-sparepart', 'PENGADAAN');
        $pengadaan = $this->buatPengguna('PENGADAAN');
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        [, $idPengajuan] = $this->buatPengajuanPembelian();

        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/arus-kas/pengajuan/{$idPengajuan}/cek")->assertStatus(200);
        $this->patch("/api/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
            'bukti'            => UploadedFile::fake()->create('bukti.jpg', 100, 'image/jpeg'),
        ])->assertStatus(200);

        $this->assertSame(1, $this->notifUntuk($pengadaan->id_pengguna, 'pengadaan_ditransfer'));
    }

    public function test_pengajuan_perawatan_disetujui_menotifikasi_pemilik_menu_perawatan(): void
    {
        $this->setIzin('/perawatan-armada', 'DISPATCHER');
        $maintenance = $this->buatPengguna('DISPATCHER');
        $this->actingAsRole('SUPERADMIN');
        $this->putJson('/api/arus-kas/pengaturan-approval', ['batas' => 999999999])->assertStatus(200);
        $idArmada = (string) Str::uuid();
        DB::table('armada')->insert(['id_armada' => $idArmada, 'id_perusahaan' => self::PERUSAHAAN_ID, 'nopol' => 'B 77 NT', 'dibuat_pada' => now()]);

        $res = $this->postJson("/api/armada/{$idArmada}/perawatan", [
            'tanggal' => now()->toDateString(), 'jenis_perawatan' => 'Servis', 'biaya' => 250000, 'status' => 'dalam_proses',
        ]);
        $res->assertStatus(201);
        $idPerawatan = $res->json('data.id_perawatan');

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $maintenance->id_pengguna, 'tipe' => 'pengadaan_disetujui',
            'referensi_tipe' => 'perawatan_armada', 'referensi_id' => $idPerawatan,
        ]);
    }
}
