# Rute Proyek (Rate Card per Proyek) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `proyek_rute` menjadi rate card tunggal (harga per rit per proyek); penawaran menyusun rute+harga tanpa `tarif_rute` lalu "Jadikan Proyek"; revisi harga via penawaran revisi; tipe borongan; kode otomatis; modul TarifRute dihapus total.

**Architecture:** Backend dulu (kode otomatis → skema rate card → lookup harga → alur penawaran → borongan/realisasi → hapus TarifRute), lalu frontend 3 gelombang. Tiap task independen teruji; suite penuh dijaga hijau di tiap task.

**Tech Stack:** Laravel 11 (pola modul Controller→Service→Repository+Contracts, sqlite in-memory tests), Next.js 15 (Ecme).

**Spec:** `docs/superpowers/specs/2026-08-17-rute-proyek-design.md` — baca bagian yang dirujuk tiap task.

## Global Constraints

- DILARANG `git commit`/`git add` (git read-only; checkpoint = laporan diff). DILARANG build/npm/docker. Test backend HANYA `vendor/bin/phpunit`. DILARANG komentar penjelas baru di kode. Semua teks UI/pesan bahasa Indonesia.
- Query DB hanya di `*Repository.php`; method repo baru wajib masuk interface `Contracts/`.
- Lookup harga trip: (id_proyek, id_rute) dengan `id_jenis_kendaraan` cocok MENANG atas baris `id_jenis_kendaraan IS NULL`; tanpa dimensi tanggal.
- Kunci harga: harga_penawaran & estimasi_ritase baris rate card hanya boleh berubah bila proyek TIDAK punya penawaran `disetujui`; pesan 422 persis `Harga terkunci — ubah lewat penawaran revisi`.
- Kontrak mobile `rute_tersedia` (TripController) TIDAK berubah bentuk.
- Format kode: `PREFIX-NNNN` (reset `tidak`), `PREFIX-YYYY-NNNN` (tahunan), `PREFIX-YYYYMM-NNNN` (bulanan); NNNN = nilai sequence pad `panjang_digit`.
- Nilai seed default kode: proyek `PRJ`/4 digit/tahunan; rute `RT`/4/tidak; penawaran `PNW`/4/bulanan.
- FE: formatNum untuk input Rp (`formatNum(Number(v))` + `.replace(/\D/g,'')`, JANGAN type="number"); DataTable pageSizes [10,25,50,100]; Dialog width={800}, DatePicker/Select tidak boleh dalam wrapper overflow; tombol Tambah solid pakai HiPlusCircle.

---

### Task 1: Kode otomatis — tabel format + sequence + helper

**Files:**
- Create: `database/migrations/2026_08_17_110001_create_pengaturan_kode_table.php` (2 tabel + seed default 3 entitas)
- Create: `app/Support/KodeOtomatis.php`
- Test: `tests/Feature/KodeOtomatisTest.php` (baru)

**Interfaces:**
- Produces: `KodeOtomatis::berikutnya(string $idPerusahaan, string $entitas): string` — dipakai Task 4 (penawaran), Task 4 (proyek), Task 7 (rute).

- [ ] **Step 1: Failing test** — kasus: (a) entitas `rute` (reset tidak) → `RT-0001`, panggil lagi → `RT-0002`; (b) `proyek` (tahunan) → `PRJ-2026-0001` (pakai `Carbon::setTestNow('2026-08-17')`); (c) `penawaran` (bulanan) → `PNW-202608-0001`; (d) sequence perusahaan A dan B terisolasi (insert 2 perusahaan); (e) entitas tanpa baris pengaturan → fallback default hardcode map yang sama dengan seed; (f) ganti prefix via update baris pengaturan → kode berikut pakai prefix baru, sequence lanjut.
- [ ] **Step 2: FAIL** `vendor/bin/phpunit --filter=KodeOtomatisTest`
- [ ] **Step 3: Implement.** Migration: `pengaturan_kode` (id_pengaturan_kode CHAR36 PK, id_perusahaan CHAR36, entitas VARCHAR(50), prefix VARCHAR(20), panjang_digit INT default 4, reset VARCHAR(10) default 'tidak', + `MigrationHelper::auditColumns`; unik (id_perusahaan, entitas)); `kode_sequence` (id_sequence PK, id_perusahaan, entitas, periode VARCHAR(10) default '', nilai_terakhir INT default 0; unik (id_perusahaan, entitas, periode)). Seed dalam migration: untuk SEMUA perusahaan existing (`SELECT id_perusahaan FROM perusahaan WHERE dihapus_pada IS NULL`), insertOrIgnore 3 baris default (lihat Global Constraints). Helper:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KodeOtomatis
{
    private const DEFAULT = [
        'proyek'    => ['prefix' => 'PRJ', 'panjang_digit' => 4, 'reset' => 'tahunan'],
        'rute'      => ['prefix' => 'RT',  'panjang_digit' => 4, 'reset' => 'tidak'],
        'penawaran' => ['prefix' => 'PNW', 'panjang_digit' => 4, 'reset' => 'bulanan'],
    ];

    public static function berikutnya(string $idPerusahaan, string $entitas): string
    {
        $aturan = DB::table('pengaturan_kode')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('entitas', $entitas)
            ->whereNull('dihapus_pada')
            ->first();

        $prefix  = $aturan->prefix ?? self::DEFAULT[$entitas]['prefix'] ?? strtoupper(substr($entitas, 0, 3));
        $digit   = (int) ($aturan->panjang_digit ?? self::DEFAULT[$entitas]['panjang_digit'] ?? 4);
        $reset   = $aturan->reset ?? self::DEFAULT[$entitas]['reset'] ?? 'tidak';
        $periode = match ($reset) {
            'tahunan' => now()->format('Y'),
            'bulanan' => now()->format('Ym'),
            default   => '',
        };

        return DB::transaction(function () use ($idPerusahaan, $entitas, $periode, $prefix, $digit) {
            $baris = DB::table('kode_sequence')
                ->where('id_perusahaan', $idPerusahaan)
                ->where('entitas', $entitas)
                ->where('periode', $periode)
                ->lockForUpdate()
                ->first();

            if ($baris === null) {
                DB::table('kode_sequence')->insert([
                    'id_sequence'    => (string) Str::uuid(),
                    'id_perusahaan'  => $idPerusahaan,
                    'entitas'        => $entitas,
                    'periode'        => $periode,
                    'nilai_terakhir' => 1,
                ]);
                $nilai = 1;
            } else {
                $nilai = (int) $baris->nilai_terakhir + 1;
                DB::table('kode_sequence')->where('id_sequence', $baris->id_sequence)
                    ->update(['nilai_terakhir' => $nilai]);
            }

            $urut = str_pad((string) $nilai, $digit, '0', STR_PAD_LEFT);
            return $periode === '' ? "{$prefix}-{$urut}" : "{$prefix}-{$periode}-{$urut}";
        });
    }
}
```

(`kode_sequence` tanpa audit columns — tabel teknis; `pengaturan_kode` pakai audit. Cek `MigrationHelper::auditColumns` untuk gaya.)
- [ ] **Step 4: PASS** filter; **Step 5:** FULL `vendor/bin/phpunit`; **Step 6:** checkpoint diff.

---

### Task 2: Skema rate card — tipe_harga, kolom ops proyek_rute, backfill, induk penawaran

**Files:**
- Create: `database/migrations/2026_08_17_110002_rate_card_proyek_rute.php` (satu file: proyek.tipe_harga; penawaran.tipe_harga + id_penawaran_induk; proyek_rute + uang_jalan/estimasi_tol/estimasi_bbm/estimasi_biaya_lain + backfill dari tarif_rute + drop proyek_rute.id_tarif_rute; drop penawaran_item.id_tarif_rute)
- Modify: `app/Modules/ProyekRute/` (Repository join tarif dihapus — baca kolom sendiri; Resource estimasi_biaya dari kolom sendiri; Store/UpdateProyekRuteRequest: hapus id_tarif_rute, tambah uang_jalan/estimasi_* nullable numeric min:0; Service: validasi duplikat (id_proyek,id_rute,id_jenis_kendaraan) → 409 `Rute dengan jenis kendaraan ini sudah terdaftar di proyek`)
- Modify: `app/Modules/Proyek/` (StoreProyekRequest: `tipe_harga in:per_rit,borongan` sometimes default per_rit; hapus `rute.*.id_tarif_rute`; Resource + model fillable tipe_harga)
- Modify: `app/Modules/Penawaran/` (Requests: hapus `items.*.id_tarif_rute`, tambah `tipe_harga`; untuk `tipe_harga=borongan` items.*.harga_satuan nullable; model+resource tipe_harga & id_penawaran_induk; PenawaranService::create/update tidak menulis id_tarif_rute)
- Test: `tests/Feature/ProyekRuteTest.php` (sesuaikan: estimasi_biaya dari kolom sendiri, duplikat 409), `ProyekTest.php` & `PenawaranItemTest.php` (hapus asumsi id_tarif_rute)

**Interfaces:**
- Produces: kolom `proyek_rute.uang_jalan/estimasi_tol/estimasi_bbm/estimasi_biaya_lain`; `proyek.tipe_harga`; `penawaran.tipe_harga`, `penawaran.id_penawaran_induk` — dipakai Task 3-6.

- [ ] **Step 1:** Failing tests (duplikat 409; kolom ops tersimpan & muncul di resource; backfill: seed tarif_rute + proyek_rute ber-id_tarif_rute → jalankan migration via `require database_path(...)` → kolom ops terisi & harga_penawaran ter-backfill bila NULL; create penawaran borongan tanpa harga_satuan → 201).
- [ ] **Step 2:** FAIL → **Step 3:** implement (backfill: `UPDATE proyek_rute pr JOIN tarif_rute t ...` — tulis portable: loop query builder, bukan raw JOIN-UPDATE, agar jalan di sqlite) → **Step 4:** PASS → **Step 5:** regression `--filter="ProyekRute|Proyek|Penawaran"` lalu FULL → **Step 6:** checkpoint.
- CATATAN: JANGAN drop tabel `tarif_rute` di task ini (konsumen resolusi masih hidup sampai Task 3; drop di Task 7).

---

### Task 3: Lookup harga trip dari rate card (konsolidasi + penagihan) + tag borongan

**Files:**
- Modify: `app/Modules/ProyekRute/ProyekRuteRepository.php` + interface — method baru `findHarga(string $idProyek, string $idRute, ?string $idJenisKendaraan): ?object` (baris jenis cocok menang; fallback baris jenis NULL; return row proyek_rute)
- Modify: `app/Modules/KonsolidasiKlien/KonsolidasiKlienService.php:14,57-69` — ganti `TarifRuteService::resolusi` → `findHarga($row->id_proyek, ...)`; hasil `tarif = ['harga' => harga_penawaran]`; bila `proyek.tipe_harga === 'borongan'` → set `borongan: true` pada baris (bukan tanpa_tarif); ringkasan `tanpa_tarif` tidak menghitung baris borongan; KonsolidasiKlienRepository pastikan select `tipe_harga` (join proyek sudah ada)
- Modify: `app/Modules/PenagihanTrip/PenagihanTripService.php:18,41-53,88-100` + `PenagihanTripRepository.php:54-71` (tambah select `id_proyek`, `tipe_harga`) — gate `bisa_ditagih` = harga ketemu && bukan borongan; pesan 422 buatDraftFaktur: `Tarif belum diatur di rute proyek` / `Trip proyek borongan difakturkan dari halaman proyek`
- Test: `KonsolidasiKlienTest.php` & `PenagihanTripTest.php` — tulis ulang kasus tarif: (a) harga dari baris jenis cocok; (b) fallback baris jenis NULL; (c) rute tak terdaftar → tanpa_tarif/422; (d) trip vendor pakai jenis kendaraan armada vendor; (e) proyek borongan → tag borongan, tidak bisa difakturkan per trip, tidak dihitung tanpa_tarif

**Interfaces:**
- Consumes: kolom Task 2. Produces: `ProyekRuteRepositoryInterface::findHarga` — dipakai juga bila modul lain butuh nanti.

- [ ] Step 1 failing tests → Step 2 FAIL → Step 3 implement → Step 4 PASS → Step 5 regression `--filter="Konsolidasi|Penagihan|Faktur|BiayaTagihan"` + FULL → Step 6 checkpoint.

---

### Task 4: Alur penawaran — nomor otomatis, tanpa tarif, Jadikan Proyek

**Files:**
- Modify: `app/Modules/Penawaran/PenawaranService.php` — create: `nomor_penawaran = KodeOtomatis::berikutnya($idPerusahaan, 'penawaran')` (field nomor dari request diabaikan); hapus sisa validasi/penulisan id_tarif_rute
- Modify: `app/Modules/Proyek/ProyekService.php` — create: `kode_proyek = KodeOtomatis::berikutnya(..., 'proyek')`; `salinRuteDariPenawaran()` disesuaikan (salin id_rute, id_jenis_kendaraan, harga_satuan→harga_penawaran, estimasi_ritase, keterangan; tanpa id_tarif_rute); saat create dengan `id_penawaran`: tipe_harga proyek = tipe_harga penawaran, `harga_penawaran` proyek = nilai_penawaran, status awal `aktif`, dan set `penawaran.id_proyek` (tautan balik, existing)
- Guard: proyek hanya bisa dibuat dari penawaran berstatus `disetujui` yang `id_proyek` masih NULL (422 bila tidak)
- Test: `ProyekTest.php` + `PenawaranTest.php` sesuaikan/tambah: kode & nomor otomatis (bukan dari input), Jadikan Proyek menyalin rate card + aktif + tautan balik, penawaran belum disetujui → 422, penawaran sudah ber-proyek → 422

- [ ] TDD seperti biasa; regression `--filter="Proyek|Penawaran|KodeOtomatis"` + FULL; checkpoint.

---

### Task 5: Kunci harga + penawaran revisi + hook disetujui

**Files:**
- Modify: `app/Modules/ProyekRute/ProyekRuteService.php` — guard update/create baris saat proyek punya penawaran `disetujui`: perubahan `harga_penawaran`/`estimasi_ritase`/baris baru → 422 `Harga terkunci — ubah lewat penawaran revisi`; `uang_jalan`+estimasi ops selalu boleh (update parsial field ops tidak kena guard); repo helper `adaPenawaranDisetujui(string $idProyek): bool` (letakkan di ProyekRuteRepository via query tabel penawaran)
- Create endpoint: `POST proyek/{id}/penawaran-revisi` (ProyekController→ProyekService::buatPenawaranRevisi) — payload `items[]` (id_rute, id_jenis_kendaraan nullable, harga_satuan, estimasi_ritase, keterangan) + `catatan`; prefill dilakukan FE; service: buat penawaran (nomor otomatis, `id_proyek`, `id_penawaran_induk` = penawaran pertama proyek [penawaran tertua ber-id_proyek], `tipe_harga` ikut proyek, `nilai_penawaran` = Σ subtotal / nilai borongan dari payload, status draft) + items
- Modify: `app/Modules/Penawaran/PenawaranService.php::updateStatus` — saat transisi ke `disetujui` DAN `id_penawaran_induk !== null`: dalam `DB::transaction` tulis balik ke rate card proyek (update harga_penawaran+estimasi_ritase baris cocok (id_rute,id_jenis_kendaraan); insert baris baru bila belum ada; baris lain dibiarkan; `proyek.harga_penawaran` = nilai_penawaran revisi). Untuk borongan: hanya update `proyek.harga_penawaran`.
- Test (`PenawaranRevisiTest.php` baru): kunci harga 422 saat ada penawaran disetujui; ops fields tetap bisa; buat revisi ber-induk benar; revisi disetujui → rate card ter-update + baris baru masuk + nilai proyek berubah; revisi ditolak → tidak ada perubahan; proyek dua pintu: proyek manual tanpa penawaran → harga bebas diedit

- [ ] TDD; regression `--filter="Penawaran|ProyekRute|Proyek"` + FULL; checkpoint.

---

### Task 6: Borongan — faktur termin dari proyek + ringkasan realisasi

**Files:**
- Create endpoint: `POST proyek/{id}/faktur-borongan` (`nominal` numeric min:1, `uraian` string required, `tanggal_faktur`, `jatuh_tempo` nullable) — guard: proyek tipe borongan (422), Σ total faktur proyek non-batal + nominal ≤ `proyek.harga_penawaran` (422 `Total faktur melebihi nilai kontrak — sisa Rp X`); buat via modul Faktur existing (satu item total + uraian — ikuti pola faktur dari konsolidasi, id_proyek + id_klien terisi)
- Modify: `app/Modules/Proyek/ProyekController.php::show` — sisipkan `realisasi`: `total_rit` (count trip selesai proyek), `nilai_realisasi` (per_rit: Σ harga baris cocok per trip + biaya tagihan; borongan: Σ total faktur non-batal), `nilai_penawaran`, `sisa_belum_difakturkan` (borongan). Query di ProyekRepository (method `ringkasanRealisasi`), boleh memakai `ProyekRuteRepository::findHarga` per trip — atau satu query agregat join; pilih yang sederhana & teruji
- Test (`FakturBoronganTest.php` baru): termin dalam batas → 201; melebihi → 422 dengan sisa; proyek per_rit → 422; realisasi per_rit & borongan di show proyek

- [ ] TDD; regression `--filter="Faktur|Proyek|Konsolidasi"` + FULL; checkpoint.

---

### Task 7: Hapus modul TarifRute + relokasi BOK + kode rute otomatis + drop tabel

**Files:**
- Move: `estimasiBok()` + endpoint → modul Rute (`GET rute/estimasi-bok`, service method dipindah utuh dari `TarifRuteService.php:145-201`; `EstimasiBokTest` arahkan ke path baru)
- Delete: `app/Modules/TarifRute/` seluruhnya + registrasi provider di `bootstrap/providers.php` (cek nama file registrasi) + `tests/Feature/TarifRuteTest.php`, `TarifRuteResolusiTest.php`
- Modify: `app/Modules/Rute/RuteService.php::create` — `kode_rute` otomatis (`KodeOtomatis`), field kode dari request diabaikan
- Create: `database/migrations/2026_08_17_110003_drop_tarif_rute.php` — drop table `tarif_rute`; soft-delete baris menu ber-path `/tarif-rute` bila masih ada (sudah nonaktif sejak 2026_07_20) + hapus baris `izin_peran` menu tsb (hard delete boleh — menu mati)
- Grep wajib sebelum selesai: `tarif_rute|TarifRute|id_tarif_rute` di `app/` & `tests/` = 0 sisa (kecuali migration historis di database/migrations — biarkan)
- Test: `RuteTest` sesuaikan (kode otomatis); `EstimasiBokTest` path baru; endpoint `GET tarif-rute*` → 404

- [ ] TDD; FULL suite; checkpoint.

---

### Task 8: FE — penawaran tanpa tarif + Jadikan Proyek; rute tanpa tarif; bersih-bersih service

**Files (semua di `D:\PROJECT-TMN\TMN-TRANSPORT-FRONTEND`):**
- Modify: `src/app/(protected-pages)/penawaran/baru/page.tsx` + `penawaran/[id]/page.tsx` — buang `tarifRuteService.resolusi` & semua rujukan tarif; harga satuan & ritase input manual (formatNum); field nomor penawaran dihilangkan (otomatis, tampil setelah simpan); tambah pilihan `tipe_harga` (per_rit/borongan; borongan: kolom harga item disembunyikan + field nilai borongan = nilai_penawaran); tombol **Jadikan Proyek** di detail saat status `disetujui` && `!id_proyek` → dialog ringan (nama proyek, tanggal mulai/selesai) → `projectService.create({ id_penawaran, ... })` → redirect ke proyek
- Modify: `src/app/(protected-pages)/penawaran/PilihRuteDialog.tsx` — hapus list/edit tarif; sisakan pemilihan rute katalog + RuteBaruDialog (tanpa bagian tarif)
- Modify: `src/components/shared/RuteBaruDialog.tsx` — lucuti props/bagian tarif; `src/components/shared/TarifFields.tsx` — HAPUS file (cek 0 pemakai tersisa)
- Modify: `src/app/(protected-pages)/rute/page.tsx`, `rute/[id]/page.tsx` (hapus card Tarif + import), `rute/baru/page.tsx` (hapus staging tarif; field kode hilang — otomatis)
- Modify: `src/services/tarifRute.service.ts` HAPUS; `estimasiBok` pindah ke `src/services/rute.service.ts` (endpoint baru `RUTE_ESTIMASI_BOK`); `src/constants/api.constant.ts` — hapus `TARIF_RUTE*`, tambah `RUTE_ESTIMASI_BOK`, `PROYEK_PENAWARAN_REVISI`, `PROYEK_FAKTUR_BORONGAN`; `src/services/penawaran.service.ts` — payload tanpa id_tarif_rute + tipe_harga + id_penawaran_induk
- Grep wajib: `tarifRute|TARIF_RUTE|id_tarif_rute` di `src/` = 0
- Verifikasi: `npx eslint` file terdampak (DILARANG npm run build)

- [ ] Implement → lint → checkpoint diff.

---

### Task 9: FE — proyek: rate card form baru, revisi, realisasi, borongan; penugasan dropdown rute proyek

**Files:**
- Rewrite: `src/components/shared/RuteTarifFields.tsx` → form rate card lugas (state per baris: id_rute [Select katalog + tombol Rute Baru], id_jenis_kendaraan [Select nullable "Semua jenis"], harga_penawaran [formatNum, read-only bila `hargaTerkunci` prop], estimasi_ritase, uang_jalan, estimasi_tol/bbm/biaya_lain [formatNum], keterangan); hapus `resolveTarifId`/resolusi/`stateDariTarifBaru` — payload langsung kolom proyek_rute
- Modify: `src/app/(protected-pages)/project/[id]/page.tsx` — card Rute Proyek pakai form baru (`hargaTerkunci` = ada penawaran disetujui, dari data proyek); tombol **Buat Penawaran Revisi** (dialog prefill baris rate card → editable → POST `proyek/{id}/penawaran-revisi`); section **Penawaran Proyek** (list: nomor, tanggal, nilai, status, badge Revisi/Induk; link ke detail penawaran); card **Realisasi** (total rit, nilai realisasi vs nilai penawaran; borongan: + sisa belum difakturkan); borongan: tombol **Buat Faktur** (dialog nominal [default sisa] + uraian + tanggal) + daftar faktur proyek
- Modify: `src/app/(protected-pages)/project/baru/page.tsx` — tipe harga; tanpa resolveTarifId; field kode hilang
- Modify: `src/app/(protected-pages)/penugasan/baru/page.tsx` + `penugasan/[id]/page.tsx` — dropdown rute dari `proyekRuteService.list(idProyek)` (pola `useEstimasiPenugasan`), uang jalan auto dari baris; hapus `tarifRuteService.resolusi`
- Modify: `src/services/proyekRute.service.ts` (kolom ops baru, tanpa id_tarif_rute), `src/services/project.service.ts` (tipe_harga, realisasi, penawaran-revisi, faktur-borongan)
- Verifikasi: `npx eslint` file terdampak

- [ ] Implement → lint → checkpoint diff.

---

### Task 10: FE — Pengaturan → Format Kode

**Files:**
- Create: `src/app/(protected-pages)/format-kode/page.tsx` — tabel entitas (proyek/rute/penawaran): kolom Entitas, Prefix (Input), Digit (Input), Reset (Select tidak/bulanan/tahunan), contoh hasil live (`PRJ-2026-0001`); Simpan per baris; header band `bg-blue-50 dark:bg-blue-500/10`; judul+subtitle page-level
- Backend kecil (ikut task ini): `GET/PUT pengaturan-kode` di modul baru ringan `app/Modules/PengaturanKode/` (list per perusahaan + update baris; role:SUPERADMIN,ADMIN) + test feature CRUD
- Create: `src/services/pengaturanKode.service.ts`, konstanta API/route, entri routes.config authority ['superadmin','admin'], migration seed menu `Format Kode` di bawah Pengaturan (pola migration seed menu approval keuangan: menu + izin lihat/ubah utk ADMIN & baris izin) 
- Verifikasi: phpunit filter modul baru + FULL; eslint

- [ ] TDD backend → FE → lint → checkpoint.
