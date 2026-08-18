# Rute Proyek (Rate Card per Proyek) — Design

Tanggal: 2026-08-17
Status: Disetujui user (arah + semua keputusan kunci)

## 1. Latar belakang

Keluhan user: pengelolaan rute/tarif berbelit — rute master global, harga di tabel `tarif_rute` terpisah (resolusi rute+jenis kendaraan+klien+tanggal), form Rute Proyek diam-diam menulis `tarif_rute` di belakang layar (`resolveTarifId`), penugasan satuan masih dropdown rute master, konsolidasi sering "Tarif belum diatur". Model mental bisnis: **harga melekat ke kontrak/proyek klien** (pola rate card TMS), bukan ke jalan.

Fondasi sudah ada: `proyek_rute` (id_proyek, id_rute, id_jenis_kendaraan, harga_penawaran, estimasi_ritase, id_tarif_rute nullable), card Rute Proyek di halaman proyek, mobile & trip-mulai & bulk-penugasan sudah membaca `proyek_rute`.

## 2. Keputusan desain (final, dari user)

1. `proyek_rute` menjadi **rate card tunggal**; mekanisme `tarif_rute` **dihapus total** (hard cutover — "hapus langsung, error diperbaiki", bukan dipelihara paralel).
2. **Penawaran pertama = tempat menyusun trip/rute + harga** (klien melihat rincian trip & harga satuan) — memilih rute dari katalog atau buat cepat, TANPA menyentuh `tarif_rute`. Setelah disetujui → jadi proyek, item tersalin menjadi rate card.
3. **Maintenance pasca-deal hanya di proyek**: uang jalan & estimasi ops bebas diedit; perubahan HARGA/penambahan rute di kontrak berjalan lewat **penawaran revisi** yang digenerate dari proyek (prefill rate card, parent = penawaran pertama) → disetujui → rate card ter-update. Riwayat harga = deretan penawaran proyek.
4. Harga ditentukan **per baris rute** (harga per rit); trip memakai harga baris yang cocok. Nilai penawaran = Σ (harga × estimasi ritase). Realisasi bisa beda total (volume nyata + biaya tambahan), harga satuan tidak pernah beda.
5. Proyek punya **tipe harga**: `per_rit` | `borongan`. Borongan: nilai kontrak satu angka; rute tetap diisi untuk operasional; faktur dari proyek (termin bebas, dijaga ≤ nilai kontrak); trip borongan tidak ditagih per rit.
6. **Kode otomatis** (tabel format + sequence): diterapkan ke kode proyek, kode rute, nomor penawaran; entitas lain menyusul via konfigurasi.
7. Master Rute tetap ada sebagai **katalog** (nama, asal→tujuan, jarak, lokasi) tanpa tarif.

## 3. Model data

### 3.1 `proyek`
- Tambah `tipe_harga` VARCHAR(20) default `per_rit` (nilai: `per_rit`|`borongan`; validasi di request, bukan ENUM DB — hindari branch driver).
- `harga_penawaran` existing = nilai kontrak (borongan: angka utuh; per_rit: Σ baris saat generate penawaran).
- Status existing dipakai: `draft` → `aktif` (otomatis saat penawaran disetujui) → `selesai`/`batal` (manual, existing).

### 3.2 `proyek_rute` (rate card)
- Tambah kolom mandiri: `uang_jalan` DECIMAL(15,2) NULL, `estimasi_tol`, `estimasi_bbm`, `estimasi_biaya_lain` DECIMAL(15,2) NULL.
- Migration backfill dari `tarif_rute` via `id_tarif_rute` (harga_penawaran juga di-backfill dari `tarif_rute.harga` bila NULL), lalu **drop kolom `id_tarif_rute`**.
- Unik logis per (id_proyek, id_rute, id_jenis_kendaraan) — id_jenis_kendaraan NULL = berlaku semua jenis; validasi duplikat di service (409).
- Harga & ritase **read-only setelah proyek aktif** (hanya berubah via penawaran revisi disetujui); `uang_jalan` + estimasi ops selalu editable.

### 3.3 `penawaran`
- Tambah `id_penawaran_induk` CHAR(36) NULL (self-reference; revisi menunjuk penawaran pertama proyek) dan `tipe_harga` VARCHAR(20) default `per_rit` (ikut tersalin ke proyek saat Jadikan Proyek).
- `id_proyek` existing dipakai (wajib terisi untuk penawaran baru — dibuat dari proyek).
- `penawaran_item`: tetap sebagai snapshot baris (id_rute, id_jenis_kendaraan, harga_satuan, estimasi_ritase, subtotal); **drop `id_tarif_rute`**. Untuk borongan: tanpa item harga per rit (item boleh berisi daftar rute dengan harga_satuan NULL), `nilai_penawaran` = nilai borongan.
- Penawaran lama (pra-cutover) dibiarkan apa adanya (historis, induk NULL).

### 3.4 Hapus
- Tabel `tarif_rute` di-**drop** (setelah backfill §3.2). Modul `TarifRute` dihapus: routes, controller, service, repository, requests, resources, tests. `estimasiBok()` + endpoint `estimasi-bok` **direlokasi ke modul Rute** (logika tidak berubah; tidak bergantung tarif_rute).
- FE: `tarifRute.service.ts` (estimasiBok pindah ke `rute.service.ts`), konstanta `TARIF_RUTE*`, `TarifFields.tsx`, bagian tarif `RuteBaruDialog`, card Tarif di `rute/[id]`, staging tarif di `rute/baru`, `resolveTarifId()` di `RuteTarifFields.tsx` (komponen dirombak, lihat §6).

### 3.5 Kode otomatis
- Tabel `pengaturan_kode`: id, id_perusahaan, `entitas` (unik per perusahaan: `proyek`|`rute`|`penawaran`), `prefix` (mis. `PRJ`), `panjang_digit` (default 4), `reset` (`tidak`|`bulanan`|`tahunan`), + audit. Seed default: proyek `PRJ`/tahunan, rute `RT`/tidak, penawaran `PNW`/bulanan.
- Tabel `kode_sequence`: id, id_perusahaan, entitas, `periode` (string: `''`|`YYYY`|`YYYYMM`), `nilai_terakhir` INT; unik (id_perusahaan, entitas, periode).
- Helper `App\Support\KodeOtomatis::berikutnya(string $idPerusahaan, string $entitas): string` — baca pengaturan (fallback default hardcode bila baris tak ada), `lockForUpdate` baris sequence dalam transaksi, format: `PREFIX-PERIODE-NNNN` (tanpa segmen periode bila reset `tidak` → `PREFIX-NNNN`). Contoh: `PRJ-2026-0007`, `RT-0012`, `PNW-202608-0003`.
- Dipakai di: ProyekService::create (kode_proyek), RuteService::create (kode_rute), generate penawaran (nomor_penawaran). Field kode di form FE jadi non-input (label "otomatis"); kode tetap unik-guard existing.
- UI kecil: halaman **Pengaturan → Format Kode** (list entitas: prefix, digit, reset — edit inline; tanpa tambah/hapus entitas dari UI).

## 4. Alur

### 4.1 Per rit
1. **Sales buat penawaran** (nomor otomatis; pilih klien; tipe `per_rit`): susun item = rute (pilih dari katalog atau "Rute Baru" dialog cepat) + jenis kendaraan + harga/rit + estimasi ritase; `nilai_penawaran` = Σ subtotal. Tanpa `tarif_rute`, tanpa resolusi — harga diketik sales sesuai nego.
2. Kirim ke klien; selama belum disetujui, item bebas diedit (nego).
3. Penawaran **disetujui** → tombol **"Jadikan Proyek"** (form ringan: nama, tanggal mulai/selesai; kode proyek otomatis; tipe harga ikut penawaran) → proyek langsung `aktif`, item penawaran tersalin jadi rate card `proyek_rute` (harga+ritase terkunci), `proyek.harga_penawaran` = nilai penawaran.
4. Operasional melengkapi rate card di proyek: uang jalan + estimasi tol/BBM/lainnya per baris (selalu editable).
5. Penugasan (satuan & bulk) memilih rute dari `proyek_rute` proyek terpilih; uang jalan auto dari baris. Mobile tidak berubah (sudah `rute_tersedia`).
6. **Revisi harga / tambah rute di kontrak berjalan**: dialog "Buat Penawaran Revisi" di proyek — prefill baris rate card saat ini, user ubah harga/ritase/tambah baris → penawaran baru (`id_penawaran_induk` = penawaran pertama, `id_proyek` terisi) → disetujui → sistem menulis balik ke `proyek_rute` (update harga/ritase baris cocok, insert baris baru; baris yang tidak ada di revisi dibiarkan).
7. **Proyek tanpa penawaran** (internal/ad-hoc) tetap boleh dibuat manual: rate card diisi langsung di proyek dan harga bebas diedit **selama proyek belum punya penawaran disetujui** — begitu ada, aturan kunci berlaku.

### 4.2 Borongan
1-2. Sama, tipe `borongan`: penawaran berisi daftar rute (harga_satuan NULL — klien tahu trip apa saja) + **nilai borongan** sebagai `nilai_penawaran`.
3. Disetujui → Jadikan Proyek → aktif; rute tersalin (tanpa harga/rit), nilai kontrak = nilai penawaran, kolom harga/rit disembunyikan di proyek (uang jalan + estimasi ops tetap).
4. Revisi nilai = penawaran revisi (nilai baru) → disetujui → `proyek.harga_penawaran` ter-update.
5. **Faktur borongan dari halaman proyek**: tombol "Buat Faktur" → nominal + uraian (default = sisa belum difakturkan); guard Σ faktur aktif (semua status kecuali batal) proyek ≤ nilai kontrak (422 bila lewat). Faktur memakai modul Faktur existing (satu item total + uraian, fitur yang sudah ada), tertaut `id_proyek`.
6. Konsolidasi klien: trip proyek borongan ditandai tag **"Borongan"** (bukan "Tarif belum diatur"), tidak bisa dicentang untuk faktur per trip. Biaya tambahan per trip (multidrop/TKBM) tetap bisa difakturkan terpisah bila diisi.

### 4.3 Lookup harga trip (per rit) — pengganti resolusi tarif
Trip → penugasan → `id_proyek`; rute trip = `jadwal_keberangkatan.id_rute`; jenis kendaraan = armada trip (internal) / jenis kendaraan armada vendor (trip vendor, existing). Cari `proyek_rute` (id_proyek, id_rute) dengan `id_jenis_kendaraan` cocok; bila tidak ada, fallback baris `id_jenis_kendaraan IS NULL`. Harga = `harga_penawaran` baris. Tidak ada dimensi tanggal. Tidak ketemu → "Tarif belum diatur di rute proyek" (gate faktur existing tetap).

Dipakai di: `KonsolidasiKlienService` (ganti `TarifRuteService::resolusi`), `PenagihanTripService` (gate `bisa_ditagih` + nilai draft faktur; repo select tambah `id_proyek`). Faktur yang sudah terbit menyimpan nilainya sendiri — tidak ter-reprice.

### 4.4 Ringkasan realisasi di proyek
Card kecil di halaman proyek: total rit aktual (jumlah trip selesai), nilai realisasi berjalan (per_rit: Σ trip × harga + biaya tagihan; borongan: Σ faktur terbit) vs nilai penawaran; untuk borongan tampil juga sisa belum difakturkan.

## 5. Perubahan Backend (ringkas per modul)

- **Proyek**: kolom tipe_harga; kode otomatis; `salinRuteDariPenawaran()` DIPERTAHANKAN & disesuaikan (salin harga_satuan+ritase ke rate card, tanpa id_tarif_rute; dipanggil dari "Jadikan Proyek"); endpoint `POST proyek/{id}/penawaran-revisi` (snapshot rate card sebagai penawaran revisi ber-induk); `POST proyek/{id}/faktur-borongan` (faktur tertaut proyek — tambah kolom `id_proyek` di faktur bila belum ada); ringkasan realisasi di detail; guard kunci harga aktif hanya bila proyek punya penawaran disetujui.
- **ProyekRute**: kolom ops baru di request/resource; hapus `id_tarif_rute`; `estimasi_biaya` dihitung dari kolom sendiri; guard read-only harga/ritase saat proyek aktif (422 `Harga terkunci — ubah lewat penawaran revisi`); duplikat (rute+jenis) → 409.
- **Penawaran**: create/edit standalone DIPERTAHANKAN (tempat menyusun rute+harga) tapi dilucuti dari `tarif_rute` (item tanpa `id_tarif_rute`, tanpa resolusi harga — harga diketik manual); nomor otomatis; `id_penawaran_induk` di skema+resource; tombol "Jadikan Proyek" di penawaran disetujui yang belum ber-proyek; saat penawaran REVISI `disetujui`: hook tulis-balik rate card proyek — dalam transaksi.
- **TarifRute**: modul dihapus; `estimasi-bok` pindah ke `Rute` (route `GET rute/estimasi-bok`, service method dipindah utuh + `ParameterBok` tetap).
- **KonsolidasiKlien / PenagihanTrip**: lookup §4.3; tag `borongan` di baris konsolidasi (dari `proyek.tipe_harga`).
- **Migrations** (urut): (1) kolom `tipe_harga`; (2) kolom ops `proyek_rute` + backfill dari `tarif_rute` + drop `proyek_rute.id_tarif_rute`; (3) drop `penawaran_item.id_tarif_rute`; (4) `id_penawaran_induk`; (5) tabel `pengaturan_kode` + `kode_sequence` + seed default; (6) **drop table `tarif_rute`**; (7) hapus baris menu/izin `tarif-rute` bila masih ada.

## 6. Perubahan Frontend

- **Proyek detail**: card Rute Proyek dirombak — `RuteTarifFields` ditulis ulang jadi form lugas (rute dari katalog + RuteBaruDialog tanpa bagian tarif, jenis kendaraan, harga/rit [read-only bila ada penawaran disetujui], ritase, uang jalan, estimasi tol/BBM/lainnya, keterangan); tombol **Buat Penawaran Revisi**; section daftar penawaran proyek (nomor, tanggal, nilai, status, induk/revisi); card ringkasan realisasi; borongan: nilai kontrak + tombol Buat Faktur + daftar faktur proyek.
- **Proyek baru**: tipe harga; tanpa `resolveTarifId`; kode otomatis (field kode hilang); jalur "Jadikan Proyek" dari penawaran disetujui (prefill klien+tipe+nilai).
- **Penawaran**: form create/edit item DIPERTAHANKAN tapi dilucuti tarif — `PilihRuteDialog` tanpa list/edit tarif (pilih rute katalog saja), harga satuan & ritase diketik manual, tanpa `resolusi()`; nomor otomatis (field hilang); tombol "Jadikan Proyek" muncul saat disetujui & belum ber-proyek.
- **Penugasan baru & edit**: dropdown rute → rute proyek (pola `useEstimasiPenugasan` yang sudah dipakai bulk); uang jalan auto.
- **Rute**: list & detail tanpa card tarif; form rute tanpa kode (otomatis); `rute/baru` tanpa staging tarif.
- **Pengaturan → Format Kode**: halaman kecil (menu baru di Pengaturan, izin ADMIN/SUPERADMIN + baris izin lihat).

## 7. Migrasi data & cutover

- Backfill §3.2 menjaga uang jalan/estimasi yang selama ini tampil dari `tarif_rute` tetap ada di `proyek_rute` — mobile & bulk penugasan tidak berubah perilaku.
- Trip lama yang rutenya tidak terdaftar di `proyek_rute` proyeknya → tampil "Tarif belum diatur di rute proyek" di konsolidasi (sama seperti kondisi tanpa tarif sekarang); dibereskan dengan menambah baris rute di proyek. Tidak ada auto-create baris dari data trip (eksplisit oleh user).
- Proyek existing: `tipe_harga = per_rit`; status & harga tidak diubah; penawaran lama historis dibiarkan.
- Sesuai keputusan user: **tidak ada jalur legacy yang dipertahankan** — error pasca-cutover diperbaiki langsung.

## 8. Testing (inti)

1. KodeOtomatis: format per reset (tidak/bulanan/tahunan), sequence per perusahaan terisolasi, concurrent-safe (lock), fallback tanpa baris pengaturan.
2. Rate card: CRUD baris + duplikat 409 + read-only saat aktif (422) + estimasi_biaya dari kolom sendiri.
3. Alur penawaran: buat penawaran ber-item tanpa tarif_rute (nilai = Σ subtotal; borongan tanpa harga satuan; nomor otomatis) → "Jadikan Proyek" menyalin item ke rate card + proyek aktif; penawaran revisi digenerate dari proyek (prefill, ber-induk, id_proyek terisi); revisi disetujui → tulis-balik rate card; ditolak → tidak menulis apa pun; kunci harga aktif hanya setelah ada penawaran disetujui.
4. Lookup harga: jenis kendaraan cocok menang atas baris NULL; tidak terdaftar → tanpa tarif; trip vendor via jenis kendaraan armada vendor; borongan → tag borongan & tidak bisa difakturkan per trip.
5. Faktur borongan: termin ≤ nilai kontrak (422 saat lewat), default sisa, batal tidak dihitung.
6. Migration backfill: komponen tarif tersalin, drop kolom/tabel bersih; regression penuh suite (konsolidasi, penagihan, trip, penawaran, proyek, mobile contract `rute_tersedia` tidak berubah bentuk).

## 9. Di luar scope

- Kode otomatis untuk master lain (jabatan, karyawan, klien, supplier) — tinggal tambah baris `pengaturan_kode` nanti + pemakaian di modulnya.
- PPN/PPH di faktur klien (gap lama, terpisah).
- Riwayat harga per periode di rate card (riwayat = deretan penawaran).
- Perubahan mobile (kontrak `rute_tersedia` dijaga tidak berubah).
- Redesign halaman list Penawaran (hanya kehilangan tombol tambah).
