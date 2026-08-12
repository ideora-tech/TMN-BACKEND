# Fase 1 Penagihan Trip — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Layar Penagihan Trip: daftar trip selesai ber-laporan per proyek/periode dengan tarif ter-resolusi, generate draft faktur klien otomatis, anti tagih dobel via `faktur_trip`.

**Architecture:** Modul backend baru `PenagihanTrip` (Controller–Service–Repository–Interface–Provider) memakai `TarifRuteService::resolusi` dan `FakturService::create`; tautan trip↔faktur di tabel `faktur_trip` tanpa unique (keaktifan dinilai dari status faktur via join). Frontend halaman baru `/penagihan-trip` di menu Keuangan.

**Tech Stack:** Laravel 11 + PHPUnit, Next.js 15 (DataTable/Card/Dialog Ecme).

**Spec:** `docs/superpowers/specs/2026-08-11-penagihan-trip-fase1-design.md`

## Global Constraints

- **DILARANG commit / build / migrate** — user jalankan sendiri.
- Test: `vendor/bin/phpunit`; frontend `npx next lint --file` + `npx tsc --noEmit`.
- Query hanya di Repository; route pakai middleware `izin:faktur` (kemampuan keuangan, tanpa seeding izin baru).
- Migration wajib kompatibel MySQL + SQLite; audit columns via `MigrationHelper::auditColumns`.
- Tanggal trip = `DATE(COALESCE(jk.waktu_berangkat, trip.dibuat_pada))` (konsisten filter trip existing).
- Armada trip supir-shift (penugasan tanpa `id_armada`) di-resolve via `alokasi_armada` (id_supir + tanggal) — konsisten hidrasi nopol TripRepository.

---

### Task 1: Migration `faktur_trip` + endpoint daftar trip siap tagih

**Files:**
- Create: `database/migrations/2026_08_11_000003_create_faktur_trip_table.php`
- Create: `app/Modules/PenagihanTrip/{PenagihanTripController,PenagihanTripService,PenagihanTripRepository,PenagihanTripServiceProvider}.php`, `Contracts/PenagihanTripRepositoryInterface.php`
- Modify: `bootstrap/providers.php` (daftarkan provider, urut alfabet)
- Test: `tests/Feature/PenagihanTripTest.php` (baru)

**Interfaces:**
- Consumes: `TarifRuteService::resolusi(string $idPerusahaan, string $idRute, string $idJenisKendaraan, ?string $idKlien, ?string $tanggal): ?TarifRuteModel`.
- Produces: `GET /api/v1/penagihan-trip?id_proyek&dari&sampai` → `data: [{id_trip, tanggal, rute, nopol, supir_nama, sumber, jarak_tempuh_km, tarif: {id_tarif_rute, harga}|null, bisa_ditagih}]`; `PenagihanTripRepositoryInterface::{tripSiapTagih, proyekInfo, insertFakturTrip, tripSudahDifakturkan}` — dipakai Task 2.

- [x] **Step 1: Migration**

```php
Schema::create('faktur_trip', function (Blueprint $table) {
    $table->char('id_faktur_trip', 36)->primary();
    $table->char('id_faktur', 36)->index();
    $table->char('id_trip', 36)->index();
    MigrationHelper::auditColumns($table);
});
```

- [x] **Step 2: Tulis test daftar (failing)** — helper contek `LaporanPerjalananTest` (proyek+klien, penugasan internal ber-armada dengan `id_jenis_kendaraan`, jadwal ber-`id_rute`, trip selesai, laporan via DB insert). Test:
  - `test_daftar_hanya_trip_selesai_ber_laporan`: trip selesai+laporan muncul; trip berjalan / tanpa laporan tidak.
  - `test_tarif_klien_spesifik_menang_dan_tanpa_tarif_tidak_bisa_ditagih`: dua tarif (umum & klien) → harga klien terpakai; trip di rute tanpa tarif → `tarif: null`, `bisa_ditagih: false`.
- [x] **Step 3: Repository `tripSiapTagih(string $idPerusahaan, string $idProyek, ?string $dari, ?string $sampai): array`** — query dasar:

```php
DB::table('trip as t')
  ->join('jadwal_keberangkatan as jk', 't.id_jadwal', '=', 'jk.id_jadwal')
  ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
  ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
  ->join('laporan_perjalanan as lp', 'lp.id_trip', '=', 't.id_trip')
  ->leftJoin('armada as a', 'p.id_armada', '=', 'a.id_armada')
  ->leftJoin('armada_vendor as av', 'p.id_armada_vendor', '=', 'av.id_armada_vendor')
  ->leftJoin('supir as s', 'p.id_supir', '=', 's.id_supir')
  ->leftJoin('supir_vendor as sv', 'p.id_supir_vendor', '=', 'sv.id_supir_vendor')
  ->leftJoin('rute as r', 'jk.id_rute', '=', 'r.id_rute')
  ->where('pr.id_perusahaan', $idPerusahaan)
  ->where('p.id_proyek', $idProyek)
  ->where('t.status', 'selesai')
  ->whereNull('t.dihapus_pada')->whereNull('jk.dihapus_pada')
  ->whereNull('p.dihapus_pada')->whereNull('lp.dihapus_pada')
  ->whereNotExists(function ($q) {
      $q->select(DB::raw(1))->from('faktur_trip as ft')
        ->join('faktur as f', 'f.id_faktur', '=', 'ft.id_faktur')
        ->whereColumn('ft.id_trip', 't.id_trip')
        ->whereNull('ft.dihapus_pada')->whereNull('f.dihapus_pada')
        ->where('f.status', '!=', 'batal');
  })
  ->when($dari, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) >= ?', [$v]))
  ->when($sampai, fn ($q, $v) => $q->whereRaw('DATE(COALESCE(jk.waktu_berangkat, t.dibuat_pada)) <= ?', [$v]))
  ->orderByRaw('COALESCE(jk.waktu_berangkat, t.dibuat_pada)')
  ->select([...kolom: t.id_trip, tanggal raw, jk.id_rute, r.nama_rute, jk.rute as rute_teks,
      p.sumber, p.id_supir, p.id_armada, a.id_jenis_kendaraan, a.nopol, av.nopol av_nopol,
      s.nama supir_nama, sv.nama sv_nama, lp.jarak_tempuh_km, pr.id_klien])
  ->get()->all();
```

  Plus fallback armada shift: untuk baris `id_armada` null ber-`id_supir`, ambil `alokasi_armada`+`armada` per (id_supir, tanggal) → isi nopol & `id_jenis_kendaraan` (pola `TripRepository::attachJadwalDetail`). Tambah `proyekInfo(string $idProyek, string $idPerusahaan): ?object` (id_proyek, id_klien, nama_proyek — whereNull dihapus_pada).
- [x] **Step 4: Service `daftar(...)`** — validasi proyek milik perusahaan (404), map baris: `nopol = a.nopol ?? av_nopol ?? alokasi`, `supir_nama = s ?? sv`, `rute = nama_rute ?? rute_teks`, resolusi tarif per baris hanya bila `id_rute && id_jenis_kendaraan` → `['tarif' => $t ? ['id_tarif_rute' =>…, 'harga' => (float)$t->harga] : null, 'bisa_ditagih' => $t !== null]`.
- [x] **Step 5: Controller + Provider** — `GET penagihan-trip` (validasi `id_proyek` required, `dari/sampai` date). Provider bind interface + route `izin:faktur`; daftarkan di `bootstrap/providers.php`.
- [x] **Step 6: Run test daftar → PASS.**

---

### Task 2: Endpoint generate draft faktur

**Files:**
- Modify: `app/Modules/PenagihanTrip/PenagihanTripService.php` (+`buatDraftFaktur`), `PenagihanTripController.php` (+`buatFaktur`), `PenagihanTripServiceProvider.php` (+route POST), repo + interface (+`insertFakturTrip`, `tripSudahDifakturkan`, lock)
- Modify: `app/Modules/Faktur/FakturRepository.php` + `Contracts/FakturRepositoryInterface.php` (+`nomorBerikutnya`)
- Test: `tests/Feature/PenagihanTripTest.php`

**Interfaces:**
- Consumes: `FakturService::create(array $data): FakturModel` (data: id_perusahaan, nomor_faktur, id_proyek, id_klien, status, tanggal_faktur, jatuh_tempo, items[{deskripsi,qty,harga_satuan}]).
- Produces: `POST /api/v1/penagihan-trip/faktur` `{id_proyek, trip_ids[], tanggal_faktur, jatuh_tempo?}` → 201 FakturResource.

- [x] **Step 1: Test generate (failing)**:
  - `test_generate_draft_faktur_dari_trip`: 3 trip (2 rute sama, 1 beda) → 201; faktur draft, nomor prefix `FK-`, items ter-grup (qty 2 & 1), total benar, `faktur_trip` 3 baris; GET daftar → kosong.
  - `test_generate_menolak_trip_tanpa_tarif_atau_sudah_difakturkan`: trip tanpa tarif → 422; generate ulang trip yang sama → 422; tidak ada faktur kedua.
  - `test_faktur_batal_membuka_trip_lagi`: setelah generate, PATCH status faktur `batal` → GET daftar memunculkan trip lagi.
- [x] **Step 2: `FakturRepository::nomorBerikutnya(string $idPerusahaan): string`** — pola `PembelianSparepartRepository::nomorBerikutnya` dengan prefix `'FK-' . now()->format('Ym') . '-'` (lockForUpdate, pad 4).
- [x] **Step 3: Service `buatDraftFaktur(array $data, string $idPerusahaan): FakturModel`** — `DB::transaction`: proyek valid (404); ambil ulang baris siap-tagih via `tripSiapTagih` (+ `lockForUpdate` di repo saat dipanggil dari sini) lalu keyBy id_trip; untuk tiap `trip_ids`: tidak ada di daftar → 422 `"Trip {id} tidak valid / sudah difakturkan"`; tarif null → 422 `"Trip {id}: tarif rute belum diatur"`. Grup per `(id_rute, harga)` → items. Panggil `FakturService::create([... status draft, nomor nomorBerikutnya, id_klien proyek])`; insert `faktur_trip` per trip (`RecordHelper::stampCreate`, kolom id_faktur+id_trip). Return faktur.
- [x] **Step 4: Controller `buatFaktur`** — validasi `id_proyek` required, `trip_ids` required|array|min:1, `trip_ids.*` string, `tanggal_faktur` required|date, `jatuh_tempo` nullable|date|after_or_equal. Respons `ApiResponse::success(new FakturResource(...), 'Draft faktur berhasil dibuat', 201)`.
- [x] **Step 5: Run PenagihanTripTest → PASS; suite penuh → PASS.**

---

### Task 3: Frontend halaman Penagihan Trip

**Files:**
- Create: `src/app/(protected-pages)/penagihan-trip/page.tsx`
- Create: `src/services/penagihanTrip.service.ts`
- Modify: `src/constants/api.constant.ts` (+`PENAGIHAN_TRIP`, `PENAGIHAN_TRIP_FAKTUR`), `src/constants/route.constant.ts` (+`PENAGIHAN_TRIP`), `src/configs/routes.config/routes.config.ts` (+route), `src/configs/navigation.config/index.ts` (item Keuangan sebelum Faktur, authority `['keuangan','manager','superadmin','admin']`, icon `receipt`)

**Interfaces:**
- Consumes: endpoint Task 1 & 2; pola persist proyek `?proyek=` + localStorage (`penagihan-trip.proyek`) dari `PenugasanVendorTab`; `ROUTES.FAKTUR_DETAIL(id)`.

- [x] **Step 1: Service** — `penagihanTripService.list(idProyek, dari, sampai)` + `buatFaktur(payload)` (axios path penuh `API_ENDPOINTS`).
- [x] **Step 2: Page** — judul "Penagihan Trip" + subtitle; filter Select proyek (opsi dari `projectService.list(1, 100)`) + 2 DatePicker (default awal & akhir bulan berjalan); tabel manual (header band `bg-blue-50 dark:bg-blue-500/10`): checkbox (disabled bila `!bisa_ditagih` + Tag "Tarif belum diatur"), Tanggal, Rute, Nopol, Supir (+Tag `vendor` bila sumber vendor), Jarak, Tarif (`formatRupiah`); bar bawah: "{n} trip • estimasi {Rp}" + Button solid `HiPlusCircle` "Buat Draft Faktur" → Dialog (DatePicker tanggal faktur default hari ini, jatuh tempo opsional, form onSubmit) → sukses: toast + `router.push(ROUTES.FAKTUR_DETAIL(id))`.
- [x] **Step 3: Registrasi** — api.constant, route.constant, routes.config (`'/penagihan-trip': { key: 'penagihan-trip', authority: [] }`), navigation item.
- [x] **Step 4: `npx next lint --file` semua file diubah + `npx tsc --noEmit` → bersih.**

---

## Verifikasi Manual (oleh user)

1. Migrate → menu Keuangan muncul "Penagihan Trip".
2. Pilih proyek ber-trip selesai + laporan → daftar muncul, tarif sesuai master; trip vendor/tanpa tarif tertanda & tak bisa dicentang.
3. Centang beberapa → Buat Draft Faktur → mendarat di detail faktur draft dengan item ter-grup; edit item masih bisa.
4. Kembali ke Penagihan Trip → trip tadi hilang; batalkan faktur → muncul lagi.
