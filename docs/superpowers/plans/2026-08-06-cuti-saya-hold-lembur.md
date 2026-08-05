# Cuti Saya Mobile + Hold Lembur — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (1) Jam pulang self check-out tidak menghasilkan lembur payroll sampai diverifikasi admin; (2) staff mobile bisa ajukan/lihat/batalkan cuti + lihat saldo lewat tab ke-4 "Cuti".

**Architecture:** Backend: flag `pulang_mandiri` di `absensi` + filter di query lembur; 4 endpoint "saya" di modul Cuti membungkus logika existing. Mobile: fitur GetX baru `karyawan/cuti` + tab ke-4.

**Tech Stack:** Laravel 11; Flutter 3.8 + GetX + Dio.

**Spec:** `docs/superpowers/specs/2026-08-06-cuti-saya-hold-lembur-design.md`

## Global Constraints

- **DILARANG perintah git yang mengubah state** — user commit manual; akhiri task dengan daftar file berubah.
- Backend test: `vendor/bin/phpunit --filter=...` (JANGAN `php artisan test`); jalankan `php artisan migrate` setelah membuat migration. Mobile: HANYA `flutter analyze` (pub get tidak perlu — tanpa dependency baru); DILARANG run/build.
- Jangan tulis komentar penjelas di kode (docblock 1 baris interface boleh).
- Backend: query hanya di `*Repository.php`; Service `abort()` untuk error. Mobile: Dio hanya di `*_repository.dart`; `Obx` granular; file ≤1000 baris; teks UI Indonesia.
- Working dir backend: `D:\PROJECT-TMN\TMN-TRANSPORT-BACKEND`; mobile: `D:\PROJECT-TMN\TMN-TRANSPORT-MOBILE`.

---

### Task 1: Backend — hold lembur `pulang_mandiri`

**Files:**
- Create: `database/migrations/2026_08_06_100001_add_pulang_mandiri_to_absensi_table.php`
- Modify: `app/Modules/Absensi/AbsensiService.php` (2 titik upsert), `app/Modules/Absensi/AbsensiRepository.php` (`jamPulangDalamRentang`)
- Test (create): `tests/Feature/LemburHoldTest.php`

**Interfaces:**
- Produces: baris absensi hasil `POST /absensi/saya/pulang` ber-`pulang_mandiri=1` dan dikecualikan dari lembur; baris hasil `POST /absensi/harian` admin ber-`pulang_mandiri=0`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->tinyInteger('pulang_mandiri')->default(0)->after('alamat_pulang');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn('pulang_mandiri');
        });
    }
};
```

Jalankan `php artisan migrate`.

- [ ] **Step 2: Test (RED)**

Buat `tests/Feature/LemburHoldTest.php` — pakai pola `loginSebagaiKaryawan()` + `setPengaturan()` dari `tests/Feature/AbsensiSayaTest.php` (salin helper-nya, sesuaikan NIK unik). Tiga skenario:

```php
    public function test_pulang_mandiri_tidak_menghasilkan_lembur(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00'));
        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(200);

        $this->assertSame(1, (int) DB::table('absensi')->where('id_karyawan', $idKaryawan)->value('pulang_mandiri'));

        $rekap = $this->getJson('/api/v1/absensi/rekap?bulan=2026-08')->assertStatus(200)->json('data');
        $baris = collect($rekap)->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(0, (int) $baris['lembur_menit']);
    }

    public function test_input_admin_menghasilkan_lembur(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => '2026-08-06',
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => 'hadir', 'jam_masuk' => '08:00', 'jam_pulang' => '20:00']],
        ])->assertStatus(200);

        $rekap = $this->getJson('/api/v1/absensi/rekap?bulan=2026-08')->json('data');
        $baris = collect($rekap)->firstWhere('id_karyawan', $idKaryawan);
        $this->assertSame(180, (int) $baris['lembur_menit']);
    }

    public function test_admin_menimpa_baris_mandiri_membuat_lembur_terhitung(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        Carbon::setTestNow(Carbon::parse('2026-08-06 20:00:00'));
        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(200);
        Carbon::setTestNow();

        $this->postJson('/api/v1/absensi/harian', [
            'tanggal' => '2026-08-06',
            'entries' => [['id_karyawan' => $idKaryawan, 'status' => 'hadir', 'jam_masuk' => '07:55', 'jam_pulang' => '20:00']],
        ])->assertStatus(200);

        $this->assertSame(0, (int) DB::table('absensi')->where('id_karyawan', $idKaryawan)->value('pulang_mandiri'));
        $rekap = $this->getJson('/api/v1/absensi/rekap?bulan=2026-08')->json('data');
        $this->assertSame(180, (int) collect($rekap)->firstWhere('id_karyawan', $idKaryawan)['lembur_menit']);
    }
```

Cek dulu format request `POST /absensi/harian` aktual di `AbsensiController::simpanHarian`/test existing `AbsensiTest` — sesuaikan payload bila beda. Ekspektasi lembur 180 menit = 17:00→20:00. Run `vendor/bin/phpunit --filter=LemburHoldTest` → RED (kolom/filter belum ada: skenario 1 gagal karena lembur masih terhitung).

- [ ] **Step 3: Implementasi**

`AbsensiService::absenPulang` — tambah `'pulang_mandiri' => 1,` di array upsert. `AbsensiService::simpanHarian` — tambah `'pulang_mandiri' => 0,` di array upsert. `AbsensiRepository::jamPulangDalamRentang` — tambah `->where('pulang_mandiri', 0)` setelah `whereNotNull('jam_pulang')`.

- [ ] **Step 4: GREEN + regresi** — `--filter=LemburHoldTest` hijau; lalu `--filter="AbsensiSayaTest|AbsensiTest|PayrollTest"` hijau.

- [ ] **Step 5: Laporkan file berubah (tanpa commit)**

---

### Task 2: Backend — endpoint cuti saya

**Files:**
- Modify: `app/Modules/Cuti/Contracts/CutiRepositoryInterface.php`, `app/Modules/Cuti/CutiRepository.php` (tambah `pengajuanByKaryawan`)
- Modify: `app/Modules/Cuti/CutiService.php` (4 method + helper), `app/Modules/Cuti/CutiController.php` (4 method), `app/Modules/Cuti/CutiServiceProvider.php` (4 route)
- Test (create): `tests/Feature/CutiSayaTest.php`

**Interfaces:**
- Consumes: `CutiService::createPengajuan/saldoInfo/pengajuanOrFail` existing; pola guard `pastikanKaryawan` dari `AbsensiService`.
- Produces (untuk Task 3): `GET pengajuan-cuti/saya` → `data` array of `{id_pengajuan, nama_jenis, tanggal_mulai, tanggal_selesai, jumlah_hari, alasan, status, catatan_proses}`; `POST pengajuan-cuti/saya` body `{id_jenis_cuti, tanggal_mulai, tanggal_selesai, alasan?}`; `POST pengajuan-cuti/saya/{id}/batalkan`; `GET saldo-cuti/saya` → `{tahun, jatah, penyesuaian, terpakai, sisa, riwayat}`.

- [ ] **Step 1: Test (RED)**

Buat `tests/Feature/CutiSayaTest.php` dengan helper `loginSebagaiKaryawan()` (pola `AbsensiSayaTest`) + helper `makeJenisCuti()` (insert `jenis_cuti` dengan `mengurangi_saldo=1, aktif=1`). Test:

```php
    public function test_ajukan_cuti_dan_muncul_di_riwayat(): void
    {
        $this->loginSebagaiKaryawan();
        $idJenis = $this->makeJenisCuti();

        $res = $this->postJson('/api/v1/pengajuan-cuti/saya', [
            'id_jenis_cuti' => $idJenis, 'tanggal_mulai' => '2026-08-10',
            'tanggal_selesai' => '2026-08-12', 'alasan' => 'Acara keluarga',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.jumlah_hari', 3)->assertJsonPath('data.status', 'menunggu');

        $list = $this->getJson('/api/v1/pengajuan-cuti/saya')->assertStatus(200)->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('menunggu', $list[0]['status']);
        $this->assertArrayHasKey('nama_jenis', $list[0]);
    }

    public function test_ajukan_tumpang_tindih_ditolak(): void { /* ajukan 10-12, lalu 11-13 → 422 */ }

    public function test_batalkan_milik_sendiri_yang_menunggu(): void { /* ajukan → batalkan → 200, status dibatalkan; batalkan lagi → 422 */ }

    public function test_batalkan_pengajuan_orang_lain_404(): void { /* seed pengajuan_cuti utk karyawan lain via DB insert → batalkan → 404 */ }

    public function test_saldo_saya_tahun_berjalan(): void { /* GET saldo-cuti/saya → jatah 12, sisa 12; setelah penyesuaian -2 via POST /saldo-cuti/penyesuaian → sisa 10 */ }

    public function test_akun_tanpa_tautan_karyawan_422(): void { /* actingAsRole tanpa link → POST saya → 422 */ }
```

Isi komentar placeholder di atas dengan implementasi nyata mengikuti pola test pertama (JANGAN biarkan sebagai komentar). `assertStatus(201)` — cek dulu `CutiController::storePengajuan` existing mengembalikan 201 atau 200 (`ApiResponse::created`?) dan samakan. Run → RED 404.

- [ ] **Step 2: Repo + interface**

`pengajuanByKaryawan(string $idKaryawan): array` — join `jenis_cuti` (ambil `nama_jenis`), `whereNull dihapus_pada` sesuai pola repo existing, `where('id_karyawan', $idKaryawan)`, `orderByDesc('dibuat_pada')`, `->get()->all()`. Ikuti gaya query method existing di `CutiRepository` (lihat `paginatePengajuan` untuk bentuk join-nya).

- [ ] **Step 3: Service — 4 method + helper**

```php
    public function listPengajuanSaya(string $idPerusahaan, ?string $idKaryawan): array
    {
        $this->pastikanKaryawan($idKaryawan);
        return $this->repo->pengajuanByKaryawan($idKaryawan);
    }

    public function createPengajuanSaya(string $idPerusahaan, ?string $idKaryawan, array $data): object
    {
        $this->pastikanKaryawan($idKaryawan);
        return $this->createPengajuan($idPerusahaan, array_merge($data, [
            'id_karyawan' => $idKaryawan,
            'id_supir'    => null,
        ]));
    }

    public function batalkanSaya(string $id, string $idPerusahaan, ?string $idKaryawan): object
    {
        $this->pastikanKaryawan($idKaryawan);
        $pengajuan = $this->pengajuanOrFail($id, $idPerusahaan);
        if ($pengajuan->id_karyawan !== $idKaryawan) {
            abort(404, 'Pengajuan cuti tidak ditemukan');
        }
        if ($pengajuan->status !== 'menunggu') {
            abort(422, 'Hanya pengajuan berstatus menunggu yang dapat dibatalkan');
        }

        return $this->repo->updatePengajuan($pengajuan, [
            'status'        => 'dibatalkan',
            'diproses_oleh' => auth()->id(),
            'diproses_pada' => now(),
        ]);
    }

    public function saldoSaya(string $idPerusahaan, ?string $idKaryawan): array
    {
        $this->pastikanKaryawan($idKaryawan);
        return $this->saldoInfo($idPerusahaan, $idKaryawan, null, (int) now()->format('Y'));
    }

    private function pastikanKaryawan(?string $idKaryawan): void
    {
        if ($idKaryawan === null || $idKaryawan === '') {
            abort(422, 'Akun Anda tidak tertaut dengan data karyawan');
        }
    }
```

- [ ] **Step 4: Controller — 4 method**

Ikuti pola `AbsensiController` absensi-saya: ambil `$user = $request->user()`, validasi `storePengajuanSaya`: `id_jenis_cuti required|string`, `tanggal_mulai required|date`, `tanggal_selesai required|date|after_or_equal:tanggal_mulai`, `alasan nullable|string|max:1000`. Response memakai helper `ApiResponse` yang sama dengan method pengajuan existing (samakan kode status create dengan `storePengajuan`).

- [ ] **Step 5: Route — SEBELUM route pengajuan-cuti existing**

```php
                Route::get('pengajuan-cuti/saya', [CutiController::class, 'indexPengajuanSaya']);
                Route::post('pengajuan-cuti/saya', [CutiController::class, 'storePengajuanSaya']);
                Route::post('pengajuan-cuti/saya/{id}/batalkan', [CutiController::class, 'batalkanSaya']);
                Route::get('saldo-cuti/saya', [CutiController::class, 'saldoSaya']);
```

- [ ] **Step 6: GREEN + suite penuh** — `--filter=CutiSayaTest` hijau; `vendor/bin/phpunit` penuh hijau.

- [ ] **Step 7: Laporkan file berubah (tanpa commit)**

---

### Task 3: Mobile — tab Cuti

**Files (di `D:\PROJECT-TMN\TMN-TRANSPORT-MOBILE`):**
- Create: `lib/features/karyawan/cuti/cuti_models.dart`, `karyawan_cuti_repository.dart`, `karyawan_cuti_controller.dart`, `karyawan_cuti_view.dart`
- Modify: `lib/features/karyawan/dashboard/views/karyawan_main_view.dart` (tab ke-4)

**Interfaces:**
- Consumes: endpoint Task 2 via `DioClient.karyawan` + `GET /jenis-cuti` existing (paginated, filter `aktif == 1 || true` di sisi mobile).

- [ ] **Step 1: Models** — `JenisCuti {id, nama}`, `SaldoCutiSaya {tahun, jatah, penyesuaian, terpakai, sisa}` (int-cast aman), `PengajuanCutiSaya {idPengajuan, namaJenis, tanggalMulai, tanggalSelesai, jumlahHari, alasan?, status, catatanProses?}` — semua `factory fromJson`.

- [ ] **Step 2: Repository** — pola `KaryawanAbsensiRepository` (try/catch `DioException` → `dioToApp`): `saldo()` → GET `/saldo-cuti/saya`; `riwayat()` → GET `/pengajuan-cuti/saya` (data array); `jenis()` → GET `/jenis-cuti` params `{limit: 999}` → filter aktif; `ajukan({idJenisCuti, tanggalMulai, tanggalSelesai, alasan?})` → POST; `batalkan(id)` → POST batalkan.

- [ ] **Step 3: Controller** — state: `saldo Rxn<SaldoCutiSaya>`, `riwayat RxList<PengajuanCutiSaya>`, `jenis RxList<JenisCuti>`, `loading`, `submitting`; `load()` (Future.wait saldo+riwayat+jenis, error → AppSnackbar); `ajukan(...)` → sukses: snackbar + `load()` + return true (untuk menutup sheet); `batalkan(id)` → sukses: snackbar + `load()`.

- [ ] **Step 4: View** — pola `KaryawanAbsensiView` (`Get.isRegistered ? find : put`, `RefreshIndicator` + `ListView`): kartu saldo gradien `gradHero` ("Sisa Cuti {tahun}" + angka `sisa` besar + baris kecil jatah/terpakai/penyesuaian); `TmnGradientButton` "Ajukan Cuti" → `Get.bottomSheet` form (dropdown jenis via `DropdownButtonFormField`, dua `showDatePicker` untuk mulai/selesai dengan tampilan `dd-mm-yyyy`, `TextField` alasan `maxLines: 3`, tombol submit dengan loading; validasi: jenis & tanggal wajib, selesai ≥ mulai — cek di sisi form sebelum kirim); list riwayat `Card` putih: nama jenis + badge status (`menunggu` → `TmnColors.statusSebagian`, `disetujui` → `statusLunas`, `ditolak` → `statusBelum`, `dibatalkan` → `statusBatal`), teks rentang tanggal + "N hari", alasan/catatan proses bila ada, `TextButton` "Batalkan" hanya saat `menunggu` → `Get.defaultDialog`/`AlertDialog` konfirmasi. Kosong → placeholder "Belum ada pengajuan cuti". Form boleh sebagai widget/method privat di file view; pisah file bila mendekati 1000 baris.

- [ ] **Step 5: Tab ke-4** — `_pages`: tambah `KaryawanCutiView()` di posisi 3 (sebelum Profil); item nav: `BottomNavigationBarItem(icon: Icon(Icons.event_busy_rounded), label: 'Cuti')`.

- [ ] **Step 6: Analyze** — `flutter analyze` → nol issue di file fitur.

- [ ] **Step 7: Laporkan file berubah (tanpa commit)**
