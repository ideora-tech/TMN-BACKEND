# Path Relatif Berkas Upload — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** DB menyimpan path relatif berkas (bukan URL absolut); URL di-generate saat dibaca via helper hybrid (nilai lama `http` pass-through).

**Architecture:** Helper statis `App\Support\PenyimpananBerkas` (simpan + resolve URL). 9 service upload memakai `simpan()`; semua titik keluaran membungkus dengan `url()`. Tanpa migration, tanpa perubahan frontend/mobile.

**Tech Stack:** Laravel 11, disk `public`.

**Spec:** `docs/superpowers/specs/2026-08-05-path-relatif-berkas-design.md`

## Global Constraints

- **DILARANG perintah git yang mengubah state** — user commit manual. Akhiri task dengan daftar file berubah.
- Test: `vendor/bin/phpunit --filter=...` (JANGAN `php artisan test`); suite penuh: `vendor/bin/phpunit`.
- Jangan tulis komentar penjelas di kode.
- Eloquent/query builder hanya di `*Repository.php` (helper hanya memanggil facade `Storage`, bukan query).
- Respons API TIDAK berubah bentuk: konsumen tetap menerima URL lengkap.
- Working dir: `D:\PROJECT-TMN\TMN-TRANSPORT-BACKEND`.

---

### Task 1: Helper `PenyimpananBerkas` + test

**Files:**
- Create: `app/Support/PenyimpananBerkas.php`
- Test (create): `tests/Feature/PenyimpananBerkasTest.php`

**Interfaces:**
- Produces: `PenyimpananBerkas::simpan(UploadedFile $file, string $folder): string` (path relatif) dan `PenyimpananBerkas::url(?string $nilai): ?string` — dipakai Task 2–4.

- [ ] **Step 1: Tulis test (RED)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PenyimpananBerkasTest extends TestCase
{
    public function test_simpan_mengembalikan_path_relatif_tanpa_http(): void
    {
        Storage::fake('public');

        $path = PenyimpananBerkas::simpan(UploadedFile::fake()->create('kontrak.pdf', 10), 'dokumen');

        $this->assertStringStartsWith('dokumen/', $path);
        $this->assertStringStartsNotWith('http', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_url_menangani_null_legacy_dan_path(): void
    {
        Storage::fake('public');

        $this->assertNull(PenyimpananBerkas::url(null));
        $this->assertNull(PenyimpananBerkas::url(''));
        $this->assertSame('http://localhost:4001/storage/dokumen/a.pdf', PenyimpananBerkas::url('http://localhost:4001/storage/dokumen/a.pdf'));
        $this->assertSame('https://cdn.contoh.id/b.pdf', PenyimpananBerkas::url('https://cdn.contoh.id/b.pdf'));
        $this->assertStringEndsWith('/storage/dokumen/c.pdf', (string) PenyimpananBerkas::url('dokumen/c.pdf'));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal karena class belum ada**

Run: `vendor/bin/phpunit --filter=PenyimpananBerkasTest`
Expected: ERROR `Class "App\Support\PenyimpananBerkas" not found`.

- [ ] **Step 3: Implementasi helper**

Buat `app/Support/PenyimpananBerkas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PenyimpananBerkas
{
    public static function simpan(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, 'public');
    }

    public static function url(?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (str_starts_with($nilai, 'http://') || str_starts_with($nilai, 'https://')) {
            return $nilai;
        }

        return Storage::disk('public')->url($nilai);
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan hijau**

Run: `vendor/bin/phpunit --filter=PenyimpananBerkasTest`
Expected: OK (2 tests)

- [ ] **Step 5: Laporkan file berubah (tanpa commit)**

---

### Task 2: Empat modul dokumen (DokumenArmada, DokumenKaryawan, DokumenVendor, KontrakKaryawan)

**Files:**
- Modify: `app/Modules/DokumenArmada/DokumenArmadaService.php:56-57, 66-67` + `app/Modules/DokumenArmada/Resources/DokumenArmadaResource.php:21`
- Modify: `app/Modules/DokumenKaryawan/DokumenKaryawanService.php:45-46, 58-59` + `app/Modules/DokumenKaryawan/Resources/DokumenKaryawanResource.php:19`
- Modify: `app/Modules/DokumenVendor/DokumenVendorService.php:55-56, 65-66` + `app/Modules/DokumenVendor/Resources/DokumenVendorResource.php:19`
- Modify: `app/Modules/KontrakKaryawan/KontrakKaryawanService.php:30-31, 46-47` + `app/Modules/KontrakKaryawan/Resources/KontrakKaryawanResource.php:21`
- Test (modify): `tests/Feature/DokumenArmadaTest.php`, `DokumenKaryawanTest.php`, `DokumenVendorTest.php`, `KontrakKaryawanTest.php`

**Interfaces:**
- Consumes: `PenyimpananBerkas::simpan/url` dari Task 1.
- Produces: kolom `url_file` di 4 tabel tsb berisi path relatif untuk upload baru; respons API tetap URL lengkap.

- [ ] **Step 1: Tambah assertion baru di test tiap modul (RED)**

Di test upload yang sudah ada pada masing-masing 4 file test (cari test yang memanggil endpoint upload dokumen/kontrak), tambahkan setelah assert sukses — sesuaikan nama tabel per modul (`dokumen_armada`, `dokumen_karyawan`, `dokumen_vendor`, `kontrak_karyawan`):

```php
        $tersimpan = (string) DB::table('dokumen_armada')->orderByDesc('dibuat_pada')->value('url_file');
        $this->assertStringStartsNotWith('http', $tersimpan);
        $this->assertStringStartsWith('dokumen/', $tersimpan);
```

Pastikan `use Illuminate\Support\Facades\DB;` ada di file test. Jalankan `vendor/bin/phpunit --filter=DokumenArmadaTest` (dan 3 lainnya) → assertion baru FAIL (nilai tersimpan masih `http...`).

- [ ] **Step 2: Ubah service — simpan path**

Pola sama untuk 8 titik (2 per service). Contoh `DokumenArmadaService.php` baris 56-57 (dan 66-67 identik):

Sebelum:
```php
            $path = $file->store('dokumen', 'public');
            $data['url_file'] = Storage::disk('public')->url($path);
```

Sesudah:
```php
            $data['url_file'] = PenyimpananBerkas::simpan($file, 'dokumen');
```

Tambahkan `use App\Support\PenyimpananBerkas;`. Hapus `use Illuminate\Support\Facades\Storage;` bila tidak terpakai lagi di file itu (cek dulu pemakaian lain).

- [ ] **Step 3: Ubah resource — resolve URL**

Contoh `DokumenArmadaResource.php:21`:

Sebelum: `'url_file' => $this->url_file,`
Sesudah: `'url_file' => PenyimpananBerkas::url($this->url_file),`

Tambahkan `use App\Support\PenyimpananBerkas;`. Ulangi untuk 3 resource lain.

- [ ] **Step 4: Jalankan test 4 modul, perbaiki assertion lama yang kadung mengharap URL di DB**

Run: `vendor/bin/phpunit --filter="DokumenArmadaTest|DokumenKaryawanTest|DokumenVendorTest|KontrakKaryawanTest"`
Bila ada assertion lama yang mengharapkan nilai DB/respons berawalan `http` dari nilai tersimpan, sesuaikan mengikuti perilaku baru (DB = path; respons = URL hasil resolve, assert dengan `assertStringContainsString('/storage/dokumen/', ...)`). Expected akhir: semua hijau.

- [ ] **Step 5: Laporkan file berubah (tanpa commit)**

---

### Task 3: Armada (foto), PembayaranVendor (bukti), LaporanPerjalanan (foto)

**Files:**
- Modify: `app/Modules/Armada/ArmadaService.php:105-109` (`simpanFoto`) + `app/Modules/Armada/Resources/ArmadaResource.php:34`
- Modify: `app/Modules/PembayaranVendor/PembayaranVendorService.php:31-32` + `app/Modules/PembayaranVendor/Resources/PembayaranVendorResource.php:21`
- Modify: `app/Modules/LaporanPerjalanan/LaporanPerjalananService.php:202-204` + `app/Modules/LaporanPerjalanan/Resources/FotoLaporanResource.php:16`
- Test (modify): `tests/Feature/ArmadaKolomLengkapTest.php` (atau test armada yang meng-upload foto — cari `'foto'` di tests/), `PembayaranVendorTest.php`, `LaporanPerjalananTest.php`

**Interfaces:**
- Consumes: helper Task 1. Pola identik Task 2.

- [ ] **Step 1: Assertion baru di test (RED)** — pola sama Task 2; tabel/kolom: `armada.url_foto` (prefix `armada/`), `pembayaran_vendor.url_bukti` (prefix `bukti-pembayaran/`), tabel foto laporan perjalanan kolom `url_file` (prefix `laporan-perjalanan/`; cek nama tabel di migration `laporan_perjalanan` bila perlu).
- [ ] **Step 2: Ubah 3 service** — `simpanFoto` di ArmadaService jadi `return PenyimpananBerkas::simpan($foto, 'armada');` (hapus 2 baris lama); dua lainnya pola Task 2 dengan folder `bukti-pembayaran` dan `laporan-perjalanan`.
- [ ] **Step 3: Ubah 3 resource** — bungkus `url_foto`/`url_bukti`/`url_file` dengan `PenyimpananBerkas::url(...)` + import.
- [ ] **Step 4: Jalankan test 3 modul, sesuaikan assertion lama** — Run: `vendor/bin/phpunit --filter="Armada|PembayaranVendorTest|LaporanPerjalananTest"`. Expected akhir: hijau.
- [ ] **Step 5: Laporkan file berubah (tanpa commit)**

---

### Task 4: PerawatanArmada & PembelianSparepart (bukti — keluaran via array Service)

**Files:**
- Modify: `app/Modules/PerawatanArmada/PerawatanArmadaService.php:174-177` (simpan) dan `:161` (keluaran `'url_file' => $b->url_file` → bungkus helper)
- Modify: `app/Modules/PembelianSparepart/PembelianSparepartService.php:171-174` (simpan) + titik keluaran daftar bukti di service yang mengonsumsi `PembelianSparepartRepository::…get(['id_bukti','url_file','nama_asli'])` (cari di `PembelianSparepartService`, bungkus tiap `url_file` dengan helper saat array disusun — JANGAN di repository)
- Test (modify): `tests/Feature/PerawatanBuktiTest.php`, `tests/Feature/PembelianBuktiRealisasiTest.php`

**Interfaces:**
- Consumes: helper Task 1.

- [ ] **Step 1: Assertion baru di test (RED)** — pola Task 2; tabel `perawatan_armada_bukti.url_file` (prefix `perawatan/`), `pembelian_sparepart_bukti.url_file` (prefix `pembelian-sparepart/`).
- [ ] **Step 2: Ubah 2 titik simpan** — pola Task 2 dengan folder `perawatan` dan `pembelian-sparepart`.
- [ ] **Step 3: Bungkus titik keluaran** — `PerawatanArmadaService.php:161` jadi `'url_file' => PenyimpananBerkas::url($b->url_file),`; lakukan hal sama di titik keluaran bukti PembelianSparepart (di Service).
- [ ] **Step 4: Jalankan test 2 modul + suite penuh**

Run: `vendor/bin/phpunit --filter="PerawatanBuktiTest|PembelianBuktiRealisasiTest"` lalu `vendor/bin/phpunit` (suite penuh).
Expected: semua hijau, tanpa regresi.

- [ ] **Step 5: Laporkan file berubah (tanpa commit)**
