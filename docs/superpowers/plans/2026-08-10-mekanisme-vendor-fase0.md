# Fase 0 Mekanisme Vendor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trip vendor menampilkan mekanisme kontrak; laporan perjalanan menolak biaya yang ditanggung vendor; satuan rate kontrak tervalidasi.

**Architecture:** `mekanisme` diekspos lewat hidrasi aktor `TripRepository::attachJadwalDetail` → `TripResource`. Guard tanggungan terpusat di satu method privat `LaporanPerjalananService` yang dipanggil semua jalur simpan. Validasi satuan cukup di FormRequest kontrak.

**Tech Stack:** Laravel 11 + PHPUnit, Next.js 15.

**Spec:** `docs/superpowers/specs/2026-08-10-mekanisme-vendor-fase0-design.md`

## Global Constraints

- **DILARANG commit / build / migrate** — user jalankan sendiri.
- Test backend: `vendor/bin/phpunit`; frontend: `npx next lint --file` + `npx tsc --noEmit`.
- Query hanya di Repository; pesan error bahasa Indonesia; tanpa komentar penjelas.
- Tidak ada migration (kolom `mekanisme` sudah ada di `kontrak_vendor`).

---

### Task 1: Backend — `mekanisme` di respons Trip

**Files:**
- Modify: `app/Modules/Trip/TripRepository.php` (dalam `attachJadwalDetail`, blok `$kontrakVendorMap` ± baris 166-170 dan loop `setRelation` ± baris 225-240)
- Modify: `app/Modules/Trip/Resources/TripResource.php` (tambah field)
- Test: `tests/Feature/TripTest.php` (extend `test_list_dan_detail_trip_vendor_menyertakan_sumber_dan_vendor_nama`)

**Interfaces:**
- Produces: field `mekanisme: 'unit_only'|'unit_driver'|'full'|null` di respons list & detail trip — dipakai Task 3 (frontend).

- [x] **Step 1: Extend test (failing)** — di test existing tambah assert `->assertJsonPath('data.mekanisme', 'full')` (detail) dan cek item list trip vendor punya `mekanisme === 'full'`, trip internal `mekanisme === null` (di `test_trip_internal_bersumber_internal_dengan_vendor_nama_null` tambah assert `mekanisme` null).
- [x] **Step 2: Run** `vendor/bin/phpunit --filter=TripTest` → test tsb FAIL.
- [x] **Step 3: Implementasi** — ganti pluck jadi get dua kolom:

```php
        $kontrakVendorMap = $idKontrakVendorList->isEmpty() ? collect()
            : DB::table('kontrak_vendor')->whereIn('id_kontrak_vendor', $idKontrakVendorList)
                ->get(['id_kontrak_vendor', 'id_vendor', 'mekanisme'])->keyBy('id_kontrak_vendor');
        $idVendorList = $kontrakVendorMap->pluck('id_vendor')->unique()->filter()->values();
```

  Di loop: `$kontrak = $penugasan?->id_kontrak_vendor !== null ? $kontrakVendorMap->get($penugasan->id_kontrak_vendor) : null;` → `$idVendor = $kontrak?->id_vendor`, tambah `$record->setRelation('mekanisme', $kontrak?->mekanisme);`. Di `TripResource`: `'mekanisme' => $this->mekanisme,` setelah `vendor_nama`.
- [x] **Step 4: Run** `vendor/bin/phpunit --filter=TripTest` → PASS.

---

### Task 2: Backend — guard tanggungan laporan + validasi satuan

**Files:**
- Modify: `app/Modules/LaporanPerjalanan/LaporanPerjalananService.php` (method privat baru + panggil di `createForTrip`, `update`, `upsertUntukSupir`)
- Modify: `app/Modules/LaporanPerjalanan/Contracts/LaporanPerjalananRepositoryInterface.php` + `LaporanPerjalananRepository.php` (method `mekanismeKontrak`)
- Modify: `app/Modules/KontrakVendor/Requests/StoreKontrakVendorRequest.php` + `UpdateKontrakVendorRequest.php` (rule `satuan`)
- Test: `tests/Feature/LaporanPerjalananTest.php` (3 test baru; pakai pola pembuatan trip vendor dari `TripTest::makeTripVendor` — salin helper dengan param `mekanisme`), `tests/Feature/KontrakVendorCrudTest.php` (1 test satuan)

**Interfaces:**
- Consumes: `TripRepositoryInterface::findPenugasanDariTrip(string $idTrip): ?object` (sudah di-inject; return `p.*` termasuk `sumber`, `id_kontrak_vendor`).
- Produces: `LaporanPerjalananRepositoryInterface::mekanismeKontrak(string $idKontrakVendor): ?string`.

- [x] **Step 1: Tulis test failing** — di `LaporanPerjalananTest` buat helper trip vendor (mekanisme param), lalu:

```php
    public function test_laporan_trip_vendor_unit_driver_tolak_uang_jalan(): void
    // POST laporan {uang_jalan: 50000} → assertStatus(422), pesan mengandung 'ditanggung vendor'
    // POST laporan {biaya_bbm: 100000, uang_tol: 20000} → assertStatus(201/200) — bbm+tol boleh

    public function test_laporan_trip_vendor_full_tolak_semua_biaya(): void
    // POST {biaya_bbm: 100000} → 422; POST {biaya_lain: [...]} → 422
    // POST {jarak_tempuh_km: 120, catatan_insiden: 'aman'} → sukses

    public function test_laporan_trip_internal_semua_biaya_diterima(): void
    // regresi: semua field → sukses
```

  Di `KontrakVendorCrudTest`: POST kontrak `satuan: 'per kilo'` → 422; `satuan: 'per trip'` → 200/201.
- [x] **Step 2: Run filter test baru → FAIL** (laporan vendor masih menerima biaya; satuan liar masih 200).
- [x] **Step 3: Repo + interface** —

```php
    public function mekanismeKontrak(string $idKontrakVendor): ?string
    {
        $mekanisme = DB::table('kontrak_vendor')
            ->whereNull('dihapus_pada')
            ->where('id_kontrak_vendor', $idKontrakVendor)
            ->value('mekanisme');

        return $mekanisme !== null ? (string) $mekanisme : null;
    }
```

- [x] **Step 4: Guard di service** — method privat:

```php
    private const LABEL_MEKANISME = ['unit_only' => 'Unit Only', 'unit_driver' => 'Unit + Driver', 'full' => 'Full'];

    private function tolakBiayaTanggunganVendor(string $idTrip, array $data): void
    {
        $penugasan = $this->tripRepo->findPenugasanDariTrip($idTrip);
        if ($penugasan === null || ($penugasan->sumber ?? 'internal') !== 'vendor' || empty($penugasan->id_kontrak_vendor)) {
            return;
        }
        $mekanisme = $this->repo->mekanismeKontrak((string) $penugasan->id_kontrak_vendor);
        if ($mekanisme === null || $mekanisme === 'unit_only') {
            return;
        }

        $label = self::LABEL_MEKANISME[$mekanisme] ?? $mekanisme;
        $terisi = fn (string $k) => isset($data[$k]) && (float) $data[$k] > 0;

        if ($terisi('uang_jalan')) {
            abort(422, "Uang jalan ditanggung vendor (kontrak {$label})");
        }
        if ($mekanisme === 'full'
            && ($terisi('biaya_bbm') || $terisi('jumlah_liter') || $terisi('uang_tol') || !empty($data['id_jenis_bbm']) || !empty($data['biaya_lain']))) {
            abort(422, "Biaya operasional ditanggung vendor (kontrak {$label})");
        }
    }
```

  Panggil di awal `createForTrip`, `update` (setelah resolve laporan → `$laporan->id_trip`), dan `upsertUntukSupir`. Rule `satuan` di kedua request kontrak: `['sometimes', 'nullable', 'string', 'in:per trip,per ton,per hari,per bulan,lumpsum']`.
- [x] **Step 5: Run test baru → PASS; lalu `vendor/bin/phpunit` penuh → PASS.**

---

### Task 3: Frontend — badge mekanisme + form laporan sadar-mekanisme

**Files:**
- Modify: `src/services/trip.service.ts` (type Trip: + `mekanisme: string | null`)
- Modify: `src/app/(protected-pages)/trip/[id]/page.tsx` (badge di info vendor + dialog laporan)
- Modify: `src/app/(protected-pages)/trip/page.tsx` (label mekanisme kecil di samping nama vendor, bila ada kolom vendor)
- Test: lint + tsc

**Interfaces:**
- Consumes: `mekanisme` dari API trip (Task 1); konstanta label/warna contek `MEKANISME_LABEL`/`MEKANISME_CLASS` dari `vendor/PenugasanVendorTab.tsx:31-38`.

- [x] **Step 1: Type + badge** — tambah `mekanisme` di type Trip; di detail trip (blok yang menampilkan `vendor_nama`) render `<Tag>` label mekanisme dengan kelas warna sesuai; di list trip tambah label kecil `(Unit Only)` dst di samping nama vendor.
- [x] **Step 2: Dialog laporan sadar-mekanisme** — di `trip/[id]/page.tsx`:
  - `const mekanisme = trip?.sumber === 'vendor' ? trip.mekanisme : null`
  - `const sembunyikanUangJalan = mekanisme === 'unit_driver' || mekanisme === 'full'`
  - `const sembunyikanBiayaOps = mekanisme === 'full'`
  - Bungkus field uang_jalan dengan kondisi; bungkus blok BBM (jenis, liter, biaya), uang_tol, dan biaya_lain dengan `!sembunyikanBiayaOps`; tampilkan info: `"Biaya {uang jalan|operasional} ditanggung vendor sesuai kontrak"`.
  - Saat submit, paksa nilai field tersembunyi: uang_jalan 0; (full) biaya_bbm 0, jumlah_liter kosong, id_jenis_bbm kosong, uang_tol 0, biaya_lain [].
- [x] **Step 3: Verifikasi** — `npx next lint --file` (3 file) + `npx tsc --noEmit` → bersih. JANGAN build/commit.

---

## Verifikasi Manual (oleh user)

1. Detail trip vendor → tampil badge mekanisme; list trip vendor ada label mekanisme.
2. Trip vendor `full` → dialog laporan hanya jarak + catatan + foto; simpan sukses.
3. Trip vendor `unit_driver` → field uang jalan hilang; BBM/tol tetap bisa diisi.
4. Trip internal → form tidak berubah.
5. Form kontrak vendor: satuan di luar daftar (via API) ditolak 422.
