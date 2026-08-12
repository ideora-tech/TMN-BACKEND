# Jenis Kendaraan Armada Vendor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Armada vendor punya relasi jenis kendaraan sehingga trip armada vendor ter-resolusi tarifnya dan bisa difakturkan di Penagihan Trip.

**Spec:** `docs/superpowers/specs/2026-08-11-jenis-kendaraan-armada-vendor-design.md`

## Global Constraints

- DILARANG commit / build / migrate — user jalankan sendiri.
- Test `vendor/bin/phpunit`; frontend `npx next lint --file` + `npx tsc --noEmit`.
- Query hanya di Repository.

### Task 1: Backend

**Files:** migration baru `2026_08_11_000005_add_jenis_kendaraan_to_armada_vendor_table.php`; `app/Modules/ArmadaVendor/{ArmadaVendorModel,ArmadaVendorService,ArmadaVendorRepository,Requests/*,Resources/*}`; `app/Modules/PenagihanTrip/{PenagihanTripRepository,PenagihanTripService}`; test `PenagihanTripTest` + `ArmadaVendor` test existing.

- [x] Step 1: Test failing — `PenagihanTripTest::test_trip_armada_vendor_ber_jenis_kendaraan_bisa_ditagih` (armada vendor + id_jenis_kendaraan + tarif umum → daftar `bisa_ditagih: true`, generate 201); test armada vendor store dengan jenis kendaraan valid & milik perusahaan lain.
- [x] Step 2: Migration kolom nullable + index.
- [x] Step 3: Model fillable, requests rules, service validasi kepemilikan (jenis_kendaraan.id_perusahaan == vendor.id_perusahaan, 404 bila tidak), repo/resource + `nama_jenis_kendaraan` (leftJoin).
- [x] Step 4: PenagihanTrip repo select `av.id_jenis_kendaraan as id_jenis_kendaraan_vendor`; service pakai fallback vendor untuk resolusi.
- [x] Step 5: Test PASS + suite penuh PASS.

### Task 2: Frontend

**Files:** `src/services/armadaVendor.service.ts` (type + payload), `armada-vendor/baru/page.tsx` + `[id]/page.tsx` (Select jenis kendaraan + tampil nama), lint + tsc.

- [x] Step 1: Type `id_jenis_kendaraan`/`nama_jenis_kendaraan`; form baru & edit Select opsional (opsi `JENIS_KENDARAAN`, extra "diperlukan agar trip armada ini bisa ditagihkan otomatis"); detail tampil nama jenis.
- [x] Step 2: Lint + tsc bersih.
