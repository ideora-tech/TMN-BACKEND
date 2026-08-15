# Re-approval Nominal Realisasi Pembelian Sparepart — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nominal pengajuan pembelian sparepart yang naik setelah lolos approval BOD memicu approval ulang; nominal turun update langsung; sinkron tidak lagi di-skip saat `dicek`/`menunggu_approval`.

**Architecture:** Tulis ulang `ArusKasService::sinkronNominalPengajuanPembelian` menjadi transaksional (lock `findPengajuanForUpdate`) dengan cabang per status pengajuan; snapshot approval lama di-soft-delete lalu dibuat ulang lewat helper baru `resetSnapshotApproval` yang memakai kembali `resolusiApprover` + `insertApprovalRows` + notifikasi. Satu method repository baru `voidApprovalRows`. Tanpa migration, tanpa endpoint baru, tanpa perubahan frontend.

**Tech Stack:** Laravel 11, PHPUnit (sqlite in-memory), pola modul Controller→Service→Repository(+Contracts).

**Spec:** `docs/superpowers/specs/2026-08-15-reapproval-nominal-realisasi-design.md`

## Global Constraints

- DILARANG menjalankan `git commit`, `git add`, atau perintah git yang mengubah state — user commit manual. Checkpoint = laporan diff, bukan commit.
- DILARANG menjalankan build (docker/npm). Test backend HANYA via `vendor/bin/phpunit` (JANGAN `php artisan test`).
- DILARANG menulis komentar penjelas di kode.
- Query Eloquent/DB HANYA di `ArusKasRepository.php`; method repo baru wajib ditambahkan juga ke `Contracts/ArusKasRepositoryInterface.php`.
- Re-approval HANYA saat nominal naik. Nominal turun atau sama TIDAK memicu approval ulang.
- Status `ditransfer` dan `ditolak` tidak boleh tersentuh sinkron sama sekali.
- Pesan abort saat approver kosong persis: `Approver keuangan belum dikonfigurasi — atur di Pengaturan → Approval Keuangan` (sama dengan `masukTahapApproval`).
- Notifikasi revisi: `tipe` = `approval_keuangan`, `referensi_tipe` = `pengajuan_pengeluaran`, judul `Pengajuan {nomor_pengajuan} perlu approval ulang`, isi `Nominal berubah dari Rp {lama} menjadi Rp {baru}` dengan `number_format($n, 0, ',', '.')`.

---

### Task 1: Rewrite sinkron nominal pembelian + reset snapshot approval + tests

**Files:**
- Modify: `app/Modules/ArusKas/ArusKasRepository.php` (tambah `voidApprovalRows` dekat `insertApprovalRows`, sekitar baris 575)
- Modify: `app/Modules/ArusKas/Contracts/ArusKasRepositoryInterface.php` (tambah signature `voidApprovalRows`)
- Modify: `app/Modules/ArusKas/ArusKasService.php` (`sinkronNominalPengajuanPembelian` baris 582-594 ditulis ulang; helper privat baru `resetSnapshotApproval` diletakkan setelah `masukTahapApproval`)
- Test: `tests/Feature/ReapprovalNominalRealisasiTest.php` (baru)

**Interfaces:**
- Consumes (sudah ada, jangan diubah): `findPengajuanByPembelian(string): ?PengajuanPengeluaranModel`, `findPengajuanForUpdate(string): ?PengajuanPengeluaranModel`, `updatePengajuan(PengajuanPengeluaranModel, array): PengajuanPengeluaranModel`, `resolusiApprover(string): array`, `insertApprovalRows(string, array): void`, `batasApproval(string): float` (service), `NotifikasiService::buatDanKirim(array)`, `RecordHelper::stampDelete(): array`.
- Produces: `voidApprovalRows(string $idPengajuan): void` di repo + interface; perilaku baru `sinkronNominalPengajuanPembelian` sesuai tabel spec §2. Tidak ada task lain yang bergantung — task tunggal.

- [ ] **Step 1: Tulis failing tests**

Buat `tests/Feature/ReapprovalNominalRealisasiTest.php`. Helper disalin dari pola test existing (`ArusKasSparepartTest` + `ApprovalKeuanganAlurTest`):

```php
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

    private function tambahApproverPengguna(string $idPengguna): void
    {
        $this->postJson('/api/v1/arus-kas/approver', [
            'tipe'        => 'pengguna',
            'id_pengguna' => $idPengguna,
        ])->assertStatus(201);
    }

    private function setBatas(float $batas): void
    {
        $this->putJson('/api/v1/arus-kas/pengaturan-approval', ['batas' => $batas])->assertStatus(200);
    }

    private function actingAsPengguna(string $idPengguna): void
    {
        Sanctum::actingAs(Pengguna::findOrFail($idPengguna), ['*']);
    }

    private function buatPembelian(): array
    {
        $create = $this->postJson('/api/v1/pembelian-sparepart', $this->payloadPembelian());
        $create->assertStatus(201);
        $idPembelian = $create->json('data.id_pembelian');
        $items = $create->json('data.items');
        $idPengajuan = $this->pengajuanUntukPembelian($idPembelian)->id_pengajuan;
        return [$idPembelian, $items, $idPengajuan];
    }

    private function setujuiSebagaiApprover(string $idPengajuan, string $idApprover): void
    {
        $this->actingAsPengguna($idApprover);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);
    }

    private function realisasi(string $idPembelian, array $items, float $hargaAktual1, float $hargaAktual2): \Illuminate\Testing\TestResponse
    {
        Storage::fake('public');
        $this->actingAsRole('SUPERADMIN');
        $this->postJson("/api/v1/pembelian-sparepart/{$idPembelian}/bukti", [
            'bukti' => [UploadedFile::fake()->image('nota.jpg')],
        ])->assertStatus(200);
        return $this->patchJson("/api/v1/pembelian-sparepart/{$idPembelian}/realisasi", [
            'tanggal_pembelian' => now()->toDateString(),
            'items'             => [
                ['id_item' => $items[0]['id_item'], 'harga_aktual' => $hargaAktual1],
                ['id_item' => $items[1]['id_item'], 'harga_aktual' => $hargaAktual2],
            ],
        ]);
    }

    private function approvalRows(string $idPengajuan, bool $termasukTerhapus = false): array
    {
        $q = DB::table('pengajuan_approval')->where('id_pengajuan', $idPengajuan);
        if (!$termasukTerhapus) {
            $q->whereNull('dihapus_pada');
        }
        return $q->orderBy('dibuat_pada')->get()->map(fn ($r) => (array) $r)->all();
    }
}
```

Estimasi pembelian selalu 200.000 (2×60.000 + 1×80.000). Realisasi `(65000, 75000)` = total aktual 205.000 (naik); realisasi `(50000, 60000)` = 160.000 (turun).

Test methods (semua di class yang sama):

```php
    public function test_realisasi_naik_melewati_batas_setelah_approval_memicu_approval_ulang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $idApprover = $this->buatPengguna('bod_satu');
        $this->tambahApproverPengguna($idApprover);
        $this->setBatas(150000);

        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
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
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

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
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

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
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
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
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);

        $this->actingAsRole('SUPERADMIN');
        $payload = $this->payloadPembelian();
        $payload['items'][0]['harga_estimasi'] = 70000;
        $this->putJson("/api/v1/pembelian-sparepart/{$idPembelian}", $payload)->assertStatus(200);

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
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'menunggu_approval');
        $this->actingAsPengguna($idApprover1);
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/approval", ['keputusan' => 'setuju'])
            ->assertStatus(200);

        $this->actingAsRole('SUPERADMIN');
        $payload = $this->payloadPembelian();
        $payload['items'][0]['harga_estimasi'] = 50000;
        $this->putJson("/api/v1/pembelian-sparepart/{$idPembelian}", $payload)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('menunggu_approval', $pengajuan->status);
        $this->assertEquals(180000, (float) $pengajuan->nominal);

        $aktif = $this->approvalRows($idPengajuan);
        $this->assertCount(2, $aktif);
        $statuses = array_column($aktif, 'status');
        sort($statuses);
        $this->assertSame(['disetujui', 'menunggu'], $statuses);
    }

    public function test_status_dicek_legacy_nominal_ikut_terupdate(): void
    {
        $this->actingAsRole('SUPERADMIN');
        [$idPembelian, , $idPengajuan] = $this->buatPembelian();
        DB::table('pengajuan_pengeluaran')->where('id_pengajuan', $idPengajuan)
            ->update(['status' => 'dicek']);

        $payload = $this->payloadPembelian();
        $payload['items'][0]['harga_estimasi'] = 70000;
        $this->putJson("/api/v1/pembelian-sparepart/{$idPembelian}", $payload)->assertStatus(200);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('dicek', $pengajuan->status);
        $this->assertEquals(220000, (float) $pengajuan->nominal);
    }

    public function test_status_ditransfer_nominal_tidak_berubah(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->setBatas(999999999);
        [$idPembelian, $items, $idPengajuan] = $this->buatPembelian();
        $this->actingAsRole('KEUANGAN');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/transfer", [
            'tanggal_transfer' => now()->toDateString(),
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
        $this->patchJson("/api/v1/arus-kas/pengajuan/{$idPengajuan}/cek")
            ->assertStatus(200)->assertJsonPath('data.status', 'disetujui');

        $stokSebelum = (float) DB::table('sparepart')
            ->where('id_sparepart', DB::table('pembelian_sparepart_item')->where('id_item', $items[0]['id_item'])->value('id_sparepart'))
            ->value('stok');

        $this->realisasi($idPembelian, $items, 65000, 75000)->assertStatus(422);

        $pengajuan = $this->pengajuanUntukPembelian($idPembelian);
        $this->assertSame('disetujui', $pengajuan->status);
        $this->assertEquals(200000, (float) $pengajuan->nominal);

        $rowPembelian = DB::table('pembelian_sparepart')->where('id_pembelian', $idPembelian)->first();
        $this->assertSame('disetujui_finance', $rowPembelian->status);

        $stokSesudah = (float) DB::table('sparepart')
            ->where('id_sparepart', DB::table('pembelian_sparepart_item')->where('id_item', $items[0]['id_item'])->value('id_sparepart'))
            ->value('stok');
        $this->assertEquals($stokSebelum, $stokSesudah);
    }
```

Catatan untuk implementer:
- Nama tabel item pembelian di test rollback: verifikasi nama tabel sebenarnya dengan membaca `PembelianSparepartRepository` (kemungkinan `pembelian_sparepart_item`; sesuaikan jika beda, mis. `pembelian_item`).
- `actingAsRole` dan `self::PERUSAHAAN_ID` sudah tersedia di `Tests\TestCase` (dipakai semua feature test).
- Endpoint transfer: verifikasi path persis via `ArusKasServiceProvider` (test existing memakai `PATCH /api/v1/arus-kas/pengajuan/{id}/transfer` — cek payload wajib, contoh di `ArusKasPengajuanTest`).
- Test `menunggu_approval` memakai edit pembelian (`PUT`) karena realisasi hanya bisa saat pembelian `disetujui_finance`; edit hanya bisa saat pembelian `diajukan` — dua jalur pemanggil sinkron yang sah.
- Payload `putJson` edit memakai `payloadPembelian()` baru — itu membuat supplier & sparepart baru, tidak masalah karena `updateWithItems` mengganti seluruh items; total estimasi baru: item pertama 2×70000 + 80000 = 220.000 (naik) atau 2×50000 + 80000 = 180.000 (turun).

- [ ] **Step 2: Jalankan test, pastikan FAIL**

Run: `vendor/bin/phpunit --filter=ReapprovalNominalRealisasiTest`
Expected: FAIL — mayoritas gagal karena perilaku belum ada (mis. status tetap `disetujui` padahal diharapkan `menunggu_approval`, nominal tidak berubah di `dicek`). Test `test_naik_tapi_masih_di_bawah_batas_tetap_disetujui` dan `test_status_ditransfer_nominal_tidak_berubah` boleh PASS (perilaku lama kebetulan sama).

- [ ] **Step 3: Implementasi**

`ArusKasRepository.php` — tambah setelah `insertApprovalRows`:

```php
    public function voidApprovalRows(string $idPengajuan): void
    {
        DB::table('pengajuan_approval')
            ->where('id_pengajuan', $idPengajuan)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
    }
```

`Contracts/ArusKasRepositoryInterface.php` — tambah di dekat `insertApprovalRows`:

```php
    public function voidApprovalRows(string $idPengajuan): void;
```

`ArusKasService.php` — ganti seluruh body `sinkronNominalPengajuanPembelian` (baris 582-594):

```php
    public function sinkronNominalPengajuanPembelian(string $idPembelian, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $record = $this->repo->findPengajuanByPembelian($idPembelian);
        if ($record === null || in_array($record->status, [self::STATUS_DITRANSFER, self::STATUS_DITOLAK], true)) {
            return;
        }

        DB::transaction(function () use ($record, $nominal) {
            $terkunci = $this->repo->findPengajuanForUpdate((string) $record->id_pengajuan);
            if ($terkunci === null || in_array($terkunci->status, [self::STATUS_DITRANSFER, self::STATUS_DITOLAK], true)) {
                return;
            }

            $nominalLama = (float) $terkunci->nominal;
            if ($nominal === $nominalLama) {
                return;
            }

            $diperbarui = $this->repo->updatePengajuan($terkunci, ['nominal' => $nominal]);

            if ($nominal < $nominalLama) {
                return;
            }

            if ($diperbarui->status === self::STATUS_MENUNGGU_APPROVAL) {
                $this->resetSnapshotApproval($diperbarui, $nominalLama);
                return;
            }

            if ($diperbarui->status === self::STATUS_DISETUJUI) {
                $batas = $this->batasApproval((string) $diperbarui->id_perusahaan);
                if ($nominal < $batas) {
                    return;
                }
                $dikembalikan = $this->repo->updatePengajuan($diperbarui, [
                    'status'         => self::STATUS_MENUNGGU_APPROVAL,
                    'disetujui_oleh' => null,
                    'disetujui_pada' => null,
                ]);
                $this->resetSnapshotApproval($dikembalikan, $nominalLama);
            }
        });
    }
```

Tambah helper privat setelah `masukTahapApproval`:

```php
    private function resetSnapshotApproval(PengajuanPengeluaranModel $record, float $nominalLama): void
    {
        $this->repo->voidApprovalRows((string) $record->id_pengajuan);

        $approvers = $this->repo->resolusiApprover((string) $record->id_perusahaan);
        if ($approvers === []) {
            abort(422, 'Approver keuangan belum dikonfigurasi — atur di Pengaturan → Approval Keuangan');
        }

        $this->repo->insertApprovalRows((string) $record->id_pengajuan, $approvers);
        foreach ($approvers as $idPengguna) {
            $this->notifikasiService->buatDanKirim([
                'id_perusahaan'  => $record->id_perusahaan,
                'id_pengguna'    => $idPengguna,
                'judul'          => "Pengajuan {$record->nomor_pengajuan} perlu approval ulang",
                'isi'            => 'Nominal berubah dari Rp ' . number_format($nominalLama, 0, ',', '.') . ' menjadi Rp ' . number_format((float) $record->nominal, 0, ',', '.'),
                'tipe'           => 'approval_keuangan',
                'referensi_id'   => $record->id_pengajuan,
                'referensi_tipe' => 'pengajuan_pengeluaran',
                'dibaca'         => 0,
            ]);
        }
    }
```

Pastikan `use App\Support\RecordHelper;` sudah ada di `ArusKasRepository.php` (sudah dipakai `insertApprovalRows`, jadi seharusnya ada).

- [ ] **Step 4: Jalankan test target, pastikan PASS**

Run: `vendor/bin/phpunit --filter=ReapprovalNominalRealisasiTest`
Expected: PASS semua (9 test).

- [ ] **Step 5: Regression suite terkait**

Run: `vendor/bin/phpunit --filter="ArusKasSparepartTest|ApprovalKeuanganAlurTest|ApprovalKeuanganKonfigTest|PembelianSparepartTest|PembelianBuktiRealisasiTest|ArusKasPengajuanTest"`
Expected: PASS semua. Perhatian khusus: `test_nominal_pengajuan_sinkron_dari_estimasi_ke_aktual_setelah_realisasi` (ArusKasSparepartTest) memakai batas 999999999 → naik tetap di bawah batas → tetap `disetujui`, harus tetap hijau; `test_realisasi_setelah_uang_muka_transfer_langsung_lunas_dan_nominal_pengajuan_tidak_berubah` (PembelianSparepartTest) menguji guard `ditransfer`.

- [ ] **Step 6: Full suite**

Run: `vendor/bin/phpunit`
Expected: PASS semua (≥1084 test sebelum penambahan; JANGAN commit — laporkan diff sebagai checkpoint).
