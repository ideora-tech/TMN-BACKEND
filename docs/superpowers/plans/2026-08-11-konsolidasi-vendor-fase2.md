# Fase 2 Konsolidasi Vendor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rekap pemakaian armada vendor per periode + export Excel konsolidasi + panel pencocokan di detail Invoice Vendor.

**Architecture:** Modul backend baru `KonsolidasiVendor` (rekap + export, `izin:invoice-vendor`); kolom `periode_dari/sampai` di `invoice_vendor`; frontend halaman `/konsolidasi-vendor` + panel pencocokan di detail invoice yang memakai endpoint rekap yang sama.

**Spec:** `docs/superpowers/specs/2026-08-11-konsolidasi-vendor-fase2-design.md`

## Global Constraints

- DILARANG commit / build / migrate — user jalankan sendiri.
- Test `vendor/bin/phpunit`; frontend `npx next lint --file` + `npx tsc --noEmit`.
- Query hanya di Repository; export pakai trait `App\Support\Exports\DenganGayaLaporan`.
- Trip yang dihitung: status `selesai` + ber-`laporan_perjalanan` + penugasan `sumber=vendor` + kontrak milik vendor.

---

### Task 1: Backend — modul KonsolidasiVendor + migration periode invoice

**Files:**
- Create: `database/migrations/2026_08_11_000004_add_periode_to_invoice_vendor_table.php`
- Create: `app/Modules/KonsolidasiVendor/{KonsolidasiVendorController,KonsolidasiVendorService,KonsolidasiVendorRepository,KonsolidasiVendorServiceProvider}.php`, `Contracts/KonsolidasiVendorRepositoryInterface.php`, `Exports/KonsolidasiVendorExport.php`
- Modify: `bootstrap/providers.php`; `app/Modules/InvoiceVendor/Requests/{Store,Update}InvoiceVendorRequest.php` + Resource (field periode)
- Test: `tests/Feature/KonsolidasiVendorTest.php` (baru), extend `tests/Feature/InvoiceVendorTest.php`

- [x] Step 1: Test failing — rekap (rit/jarak/nilai per satuan), scoping perusahaan 404, export 200, periode invoice tersimpan.
- [x] Step 2: Migration `periode_dari`/`periode_sampai` DATE NULL after `no_do`.
- [x] Step 3: Repo `tripVendor(idPerusahaan, idVendor, dari, sampai)` (join trip→jk→p→kontrak_vendor→vendor + laporan + armada_vendor/supir/supir_vendor + rute; filter kontrak.id_vendor) + `vendorInfo`, `kontrakVendor(idVendor)`.
- [x] Step 4: Service `rekap()` (ringkasan per kontrak: jumlah_rit, nilai_seharusnya bila satuan 'per trip' dan rate terisi) + `exportExcel()` (Export FromArray + DenganGayaLaporan, baris TOTAL).
- [x] Step 5: Controller (GET rekap, GET export/excel) + Provider route `izin:invoice-vendor` + daftar providers.php. Requests invoice vendor: `periode_dari` nullable date, `periode_sampai` nullable date after_or_equal:periode_dari; Resource ikut.
- [x] Step 6: Test PASS + suite penuh PASS.

### Task 2: Frontend — halaman konsolidasi + panel pencocokan invoice

**Files:**
- Create: `src/app/(protected-pages)/konsolidasi-vendor/page.tsx`, `src/services/konsolidasiVendor.service.ts`
- Modify: `src/constants/api.constant.ts`, `src/constants/route.constant.ts`, `src/configs/routes.config/routes.config.ts`, `src/configs/navigation.config/index.ts` (item Keuangan setelah Invoice Vendor)
- Modify: `src/app/(protected-pages)/invoice-vendor/baru/page.tsx` + `[id]/page.tsx` (field periode + panel pencocokan; cek service invoiceVendor utk field baru)

- [x] Step 1: Service list + exportExcel (blob, pola alokasiArmada.service) — types Rekap.
- [x] Step 2: Halaman: Select vendor (persist `?vendor=` + localStorage `konsolidasi-vendor.vendor`) + 2 DatePicker (bulan berjalan); kartu ringkasan (total rit, jarak, nilai per kontrak); tabel trip (header band biru); Button "Export Excel".
- [x] Step 3: Invoice vendor — form baru & edit: 2 DatePicker periode (opsional); detail: panel "Pencocokan Konsolidasi" bila periode terisi (fetch rekap → total rit, nilai seharusnya vs total invoice, selisih hijau/merah; satuan non-'per trip' → tampil nilai kontrak + "hitung manual").
- [x] Step 4: Registrasi konstanta/route/nav; lint semua file + tsc bersih.

## Verifikasi Manual (oleh user)

1. Migrate → menu "Konsolidasi Vendor" muncul; pilih vendor+periode → ringkasan & daftar trip benar; export Excel rapi.
2. Invoice vendor: isi periode → detail menampilkan pencocokan dengan selisih.
