# Multidrop Trip + Biaya Tagihan Trip — Design Spec

**Tanggal:** 2026-08-14
**Status:** Disetujui user (opsi hybrid + tabel biaya tagihan generik), menunggu review spec tertulis
**Referensi:** Laporan `PI ASTRO ONCALL JULI 2026 (2).xlsx` — satu trip on-call bisa punya s.d. 6 titik tujuan (kolom TRIP 1–6) plus ADD COST yang ditagihkan ke klien: MULTIDROP (mis. drop ke-4 +Rp150.000) dan TKBM.

## 1. Masalah

Rute di sistem berstruktur tunggal `asal → tujuan`, sehingga:

1. Titik-titik drop per trip tidak tercatat terstruktur (sekarang diakali lewat nama rute).
2. Biaya tambahan yang ditagihkan ke klien (multidrop, TKBM, dst.) tidak pernah masuk tagihan — `buatFaktur` hanya menghitung tarif rute. Contoh nyata: trip 08 Jul (B 9566 TXT) seharusnya tertagih 900rb + 150rb multidrop = 1.050.000, sistem hanya menagih 900rb.
3. `biaya_lain_trip` yang ada TIDAK bisa dipakai untuk ini karena ikut dihitung ke settlement supir (`total_realisasi`) — mencampur biaya tagihan klien ke sana merusak settlement.

## 2. Keputusan Desain (sudah dikunci user)

| Keputusan | Pilihan |
|---|---|
| Model data | **Hybrid**: rencana drop di penugasan → disalin ke trip saat trip dibuat → realisasi (trip) jadi sumber kebenaran |
| Titik input | Rencana saat buat/edit penugasan; koreksi realisasi di detail trip |
| Biaya tagihan | **Tabel generik `biaya_tagihan_trip`** (nama + nominal, input manual per trip) — menampung multidrop, TKBM, dan add-cost klien lain sekaligus; bukan kolom khusus per jenis, bukan aturan otomatis per proyek |
| Cakupan UI | Form penugasan (internal & vendor), detail trip + trip monitor, Konsolidasi Klien + export Excel, faktur. **Mobile supir di luar cakupan.** |
| Master rute | TIDAK diubah — rute tetap "zona bertarif" (di data ASTRO harga tidak tergantung kombinasi drop). Template drop di rute = ide masa depan, bukan sekarang |

## 3. Perubahan Data

### 3.1 Tabel baru `titik_drop_penugasan` (rencana)

| Kolom | Tipe | Ket |
|---|---|---|
| id_titik_drop | char(36) PK | |
| id_penugasan | char(36), index | FK logis ke penugasan |
| urutan | tinyint unsigned | 1..n |
| lokasi | varchar(200) | teks bebas, mis. "JLB" |
| audit | `MigrationHelper::auditColumns` | dibuat/diubah/dihapus \_pada & \_oleh |

### 3.2 Tabel baru `titik_drop_trip` (realisasi)

Struktur identik 3.1, tetapi `id_trip` (char 36, index) menggantikan `id_penugasan`.

### 3.3 Tabel baru `biaya_tagihan_trip` (add cost sisi klien)

Kembaran `biaya_lain_trip` tetapi khusus biaya yang DITAGIHKAN KE KLIEN — terpisah total dari biaya operasional supir sehingga settlement tidak terganggu.

| Kolom | Tipe | Ket |
|---|---|---|
| id_biaya_tagihan | char(36) PK | |
| id_laporan | char(36), index | FK logis ke laporan_perjalanan (pola sama dgn biaya_lain_trip) |
| nama_biaya | varchar(100) | mis. "Multidrop", "TKBM" |
| nominal | decimal(15,2) default 0 | |
| audit | `MigrationHelper::auditColumns` | |

## 4. Alur

1. **Rencana** — form penugasan (internal & vendor, satu endpoint `POST/PUT /penugasan`) menerima array opsional `titik_drop: string[]` (urutan mengikuti indeks). Service menyimpan/replace baris `titik_drop_penugasan` (baris lama soft-delete, baris baru insert).
2. **Salin saat trip dibuat** — `TripService::mulaiDariPenugasan` dan `mulaiDariPenugasanUntukSupir` menyalin daftar drop aktif penugasan → `titik_drop_trip` dalam transaksi pembuatan trip (idiom snapshot yang sudah dipakai `jadwal.rute`). Edit penugasan setelah trip dibuat TIDAK mengubah trip yang sudah ada — hanya trip berikutnya.
3. **Koreksi realisasi** — endpoint baru `PUT /trip/{id}/titik-drop` (body `{ titik_drop: string[] }`, replace-all) untuk ops mengedit drop trip dari halaman detail trip. Biaya tagihan diedit lewat endpoint laporan perjalanan yang sudah ada: array `biaya_tagihan` (`nama_biaya` + `nominal`), perilaku replace-all mengikuti pola `biaya_lain` yang sudah ada.
4. **Tampilan** — detail trip & trip monitor menampilkan rangkaian drop; Konsolidasi Klien menampilkan kolom Tujuan = "JLB → MRY → RDS" (fallback `rute.tujuan` bila trip tanpa drop) + kolom "Biaya Tambahan". Export Excel `KonsolidasiKlienExport`: sel Tujuan diisi gabungan drop yang sama, kolom baru "Biaya Tambahan" ditaruh setelah kolom Tarif, dan kolom Total per baris = tarif + biaya.
5. **Penagihan** — `PenagihanTripService`:
   - Nilai tagih per trip = `tarif.harga + SUM(biaya_tagihan.nominal)` trip itu.
   - Ringkasan konsolidasi (`estimasi_nilai`) dan total trip terpilih di frontend ikut menjumlahkan biaya tagihan.
   - `buatFaktur`: item tarif tetap digroup per rute+harga seperti sekarang; SETIAP baris biaya tagihan menjadi satu `faktur_item` terpisah (deskripsi "{nama_biaya} — {nopol}, {tanggal}", qty 1).

## 5. Guard & Error Handling

- `titik_drop.*` wajib string non-kosong, max 200 karakter, maksimal **10 titik** per penugasan/trip.
- `biaya_tagihan.*.nama_biaya` wajib (max 100), `nominal >= 0`, maksimal **10 baris** per trip; **terkunci** (422) bila trip sudah terhubung faktur aktif (`faktur_trip` hidup + faktur status != batal) — angka tagihan tidak boleh berubah setelah difakturkan. Guard yang sama untuk `PUT /trip/{id}/titik-drop`.
- Trip lama (dibuat sebelum fitur) tanpa drop → tampilan fallback ke tujuan rute, biaya tagihan kosong; tidak perlu backfill.
- Semua query baris drop pakai `whereNull('dihapus_pada')` + urut `urutan`.

## 6. Perubahan per Komponen

**Backend (modul):**
- `Penugasan` — request rules `titik_drop`, service simpan/replace, repository query drop, resource menyertakan `titik_drop`.
- `Trip` — salin drop di dua pintu pembuatan trip; endpoint `PUT /trip/{id}/titik-drop`; detail & list monitor menyertakan `titik_drop`.
- `LaporanPerjalanan` — tabel `biaya_tagihan_trip` + rules array `biaya_tagihan`, simpan replace-all mengikuti pola `biaya_lain`, resource, guard pasca-faktur.
- `KonsolidasiKlien` — repo ambil drop per trip dan biaya tagihan per trip (query terpisah di-group `id_trip`, hindari join meledak); service & export.
- `PenagihanTrip` — kalkulasi nilai + item faktur per baris biaya tagihan.

**Frontend (web):**
- `penugasan/page.tsx` & `vendor/PenugasanVendorTab.tsx` — daftar baris "Titik Drop" dinamis di dialog create/edit.
- `trip/[id]/page.tsx` — tampil + edit drop; baris dinamis "Biaya Tagihan Klien" (nama + nominal) di bagian laporan, pola UI sama dengan biaya lain yang sudah ada.
- Trip monitor (`TripAktifTab`) — tampil rangkaian drop.
- `konsolidasi-klien/page.tsx` — kolom Tujuan (drop) + Biaya Tambahan; total terpilih += biaya.

## 7. Testing (backend, phpunit)

1. Simpan & replace titik drop lewat endpoint penugasan.
2. Drop tersalin ke trip saat `mulaiDariPenugasan` (dan varian supir).
3. Edit drop penugasan setelah trip dibuat → drop trip tidak berubah.
4. `PUT /trip/{id}/titik-drop` replace realisasi; ditolak bila sudah difakturkan.
5. Baris `biaya_tagihan` tersimpan & ter-replace via laporan; ditolak bila sudah difakturkan; tidak memengaruhi `total_realisasi` settlement supir.
6. Konsolidasi klien mengembalikan `titik_drop` + total `biaya_tagihan`, estimasi nilai ikut biaya.
7. `buatFaktur` menghasilkan item per baris biaya tagihan dan total faktur benar (kasus 900rb + 150rb multidrop = 1.050.000).

## 8. Di Luar Cakupan (sadar, menyusul terpisah)

- PPN/PPH di faktur klien.
- Nomor DO per trip.
- Mobile supir (lihat/isi drop dari app).
- Template drop di master rute untuk pola tetap.
