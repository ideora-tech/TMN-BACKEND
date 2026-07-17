# Design: Modul Pemeliharaan — Grup Menu Baru, Master Data, Spare Part + Stok

**Tanggal:** 2026-07-17
**Status:** Approved user ("eksekusi bro"), lanjut implementasi

## Latar Belakang

Lanjutan dari batch "Dashboard Perawatan Armada & Dokumen Armada" (2026-07-16). User meminta:
1. Form perawatan JANGAN modal — pakai halaman terpisah.
2. "Jenis Perawatan" dibuatkan master data (sekarang free-text).
3. Saat servis bisa mencatat spare part apa saja yang diganti.
4. Maintenance jadi grup menu sendiri, terpisah dari Operasional.

Keputusan user (via AskUserQuestion):
- **Spare part = master data + stok gudang** (opsi terbesar): master sparepart + tracking mutasi stok, stok berkurang otomatis saat dipakai servis. Penerimaan barang cukup form "Tambah Stok" sederhana (qty, harga beli, keterangan) — TANPA manajemen supplier/PO (YAGNI, bisa nyusul).
- **Grup menu berisi:** Perawatan Armada (pindah), Dokumen Armada (pindah), Jenis Perawatan (baru), Spare Part (baru).
- **Modal→halaman: Perawatan saja.** Dokumen Armada tetap modal.
- Label grup: **"Pemeliharaan"** (konsisten bahasa Indonesia; user bisa ganti via menu-admin).

Klarifikasi penting yang sudah dijawab dengan bukti: menu Penugasan TIDAK terhapus — baris DB utuh & muncul di `menu/tree`; user hanya perlu refresh.

## Skema Database (5 migration file, semua baru)

1. **`jenis_perawatan`**: `id_jenis_perawatan` char(36) PK, `id_perusahaan` char(36), `nama` varchar(150), `keterangan` text null, `aktif` tinyint default 1, audit columns.
2. **`sparepart`**: `id_sparepart` char(36) PK, `id_perusahaan` char(36), `kode` varchar(50), `nama` varchar(150), `satuan` varchar(30) default 'pcs', `harga_standar` decimal(15,2) default 0, `stok` int default 0, `aktif` tinyint default 1, audit. `kode` TIDAK unique di DB — uniqueness dicek app-level per `id_perusahaan` (pola Rute, menghindari masalah global-unique `kode_*` yang sudah di-flag di batch sebelumnya).
3. **`sparepart_mutasi`**: `id_mutasi` char(36) PK, `id_sparepart` char(36), `jenis` enum('masuk','keluar','penyesuaian'), `qty` int (selalu positif; arah dari `jenis`), `harga` decimal(15,2) null, `id_perawatan` char(36) null (referensi ke servis), `keterangan` text null, `tanggal` date, audit.
4. **`perawatan_sparepart`**: `id_perawatan_sparepart` char(36) PK, `id_perawatan` char(36), `id_sparepart` char(36), `nama_sparepart` varchar(150) (snapshot nama saat dipakai — riwayat servis stabil walau master di-rename/dihapus), `qty` int, `harga` decimal(15,2) (harga aktual per unit), audit.
5. **`perawatan_armada`** tambah kolom: `id_jenis_perawatan` char(36) nullable after `id_armada`. Kolom `jenis_perawatan` (varchar existing) dipertahankan sebagai **snapshot nama** — pola identik dengan `jadwal_keberangkatan.rute` + `id_rute` (fitur 2026-07-16): `id_jenis_perawatan` = sumber kebenaran, kolom teks di-sync otomatis di Service setiap `id_jenis_perawatan` berubah, konsumen lama tetap jalan tanpa diubah.

## Backend (Query Builder penuh, pola batch sebelumnya)

### Modul baru `JenisPerawatan` (`app/Modules/JenisPerawatan/`)
Mirror persis struktur `JenisKendaraan` (modul QB master-data paling sederhana): Controller/Service/Repository/Contracts/Requests/Resources, `Route::apiResource('jenis-perawatan')` dengan middleware `['api','auth:sanctum']` (master data TIDAK pakai izin middleware — konsisten JenisKendaraan). Scoping `id_perusahaan`, RecordHelper, `private const COLUMNS`, no SELECT *.

### Modul baru `Sparepart` (`app/Modules/Sparepart/`)
- CRUD standar sama seperti di atas (+ `search` param di index: cari kode/nama).
- Validasi app-level: `kode` unik per perusahaan (create + update exclude self) → 422 kalau duplikat.
- **`POST sparepart/{id}/stok`** — tambah/penyesuaian stok: payload `{jenis: 'masuk'|'penyesuaian', qty: int, harga?: nullable, keterangan?: nullable}`. Semantik final: `masuk` = qty wajib > 0, menambah stok (barang datang). `penyesuaian` = qty delta bertanda (boleh negatif/positif) untuk koreksi stok opname. Keduanya dicatat ke `sparepart_mutasi` apa adanya. Hasil akhir stok tidak boleh negatif → 422.
- **`GET sparepart/{id}/mutasi`** — riwayat mutasi paginated, terbaru dulu.
- `delete` sparepart = soft delete master (mutasi & pemakaian historis tetap ada).

### Perluasan `PerawatanArmada`
- Store/Update request tambah: `id_jenis_perawatan` (`sometimes|nullable|exists:jenis_perawatan,id_jenis_perawatan`) dan `sparepart` (`sometimes|array`, tiap item `{id_sparepart: required exists, qty: required int min 1, harga: required numeric min 0}`).
- `jenis_perawatan` (teks) tetap diterima untuk backward-compat, tapi kalau `id_jenis_perawatan` dikirim maka teks di-overwrite snapshot `nama` dari master (di Service, pola `applyRuteSnapshot`).
- **Logika stok (dalam `DB::transaction`):**
  - **Create:** validasi stok cukup per item (`lockForUpdate` baris sparepart) → insert perawatan → insert lines (dgn snapshot `nama_sparepart`) → decrement `sparepart.stok` → insert `sparepart_mutasi` jenis `keluar` per item, `id_perawatan` terisi, keterangan otomatis.
  - **Update:** hitung delta per `id_sparepart` (total baru − total lama aktif); delta>0 validasi stok; terapkan perubahan stok; catat mutasi (`keluar` utk delta+, `masuk` utk delta−, keterangan "Perubahan item servis"); lines lama di-soft-delete, lines baru di-insert fresh.
  - **Delete servis:** kembalikan stok semua lines aktif (mutasi `masuk`, keterangan "Pembatalan servis"), soft-delete lines, soft-delete perawatan.
  - **Stok tidak cukup** → abort 422: `"Stok {nama} tidak cukup (tersisa {stok}, diminta {qty})"`.
- Resource: tambah `id_jenis_perawatan` + array `sparepart` (`id_perawatan_sparepart, id_sparepart, nama_sparepart, qty, harga, subtotal`) — di-attach batched (whereIn) di `findById` dan TIDAK di list paginated (list cukup ringkas).

### Migration menu (1 file)
- Grup baru `Pemeliharaan`: `id_menu = m0000001-0000-4000-8000-000000000080`, path null, icon `wrench`, urutan 9 (paling bawah; user bisa reorder via halaman Menu admin — TIDAK merenumber grup lain).
- Update `id_menu_induk` menu `...028` (Perawatan Armada) & `...029` (Dokumen Armada) → `...080`, urutan 1 & 2.
- Menu baru: `Jenis Perawatan` (`...081`, path `/jenis-perawatan`, icon `clipboard`, urutan 3) dan `Spare Part` (`...082`, path `/sparepart`, icon `puzzle`, urutan 4). Icon `clipboard`/`puzzle`/`wrench` semua SUDAH ada di `navigation-icon.config.tsx` — tidak perlu edit frontend icon.
- `menu_peran`: DISPATCHER/MANAGER/ADMIN/SUPERADMIN untuk grup + 2 menu baru (menu 028/029 sudah punya).
- `down()` simetris: kembalikan induk 028/029 ke Operasional (urutan 8/9), hapus grup + 2 menu baru + menu_peran-nya.

## Frontend

### Services & konstanta
- `jenisPerawatan.service.ts` (mirror `jenisBbm.service.ts`): interface + list/get/create/update/delete.
- `sparepart.service.ts`: interface `Sparepart`, `SparepartMutasi`; list (dgn search)/get/create/update/delete + `tambahStok(id, payload)` + `listMutasi(id, page)`.
- `perawatanArmada.service.ts`: interface `PerawatanSparepartItem`; payload create/update tambah `id_jenis_perawatan` & `sparepart: items[]`; `PerawatanArmada` interface tambah field baru; tambah method `get(idArmada, id)` memanggil endpoint `GET armada/{idArmada}/perawatan/{id}` (route `show` sudah terdaftar sejak awal, belum pernah dipakai frontend).
- `api.constant.ts` & `route.constant.ts` & `routes.config.ts`: entri `JENIS_PERAWATAN`, `SPAREPART` (listRoute triple), `/perawatan-armada/baru`, `/perawatan-armada/[id]`.

### Halaman
1. **`/jenis-perawatan`** (+ `/baru`, `/[id]`): mirror pola halaman `jenis-bbm` (list + halaman form terpisah). Field: nama, keterangan, aktif.
2. **`/sparepart`** (list: kolom kode, nama, satuan, harga standar, stok [badge merah jika 0], aktif, aksi; search) + **`/sparepart/baru`** (form) + **`/sparepart/[id]`** (info + edit + **riwayat mutasi** tabel + dialog **"Tambah Stok"**).
3. **`/perawatan-armada`** (list existing): modal create/edit DIHAPUS → tombol "Catat Perawatan" navigate ke `/perawatan-armada/baru`; icon edit navigate ke `/perawatan-armada/{id}?armada={id_armada}` (query param membawa `id_armada` karena endpoint show/update nested butuh keduanya; list response sudah punya `id_armada` per row).
4. **`/perawatan-armada/baru`** dan **`/perawatan-armada/[id]`**: form full-page — dropdown Armada (disabled saat edit), dropdown Jenis Perawatan (master), tanggal, biaya, KM, status, jadwal servis berikutnya, keterangan, **section "Spare Part Diganti"**: baris dinamis (dropdown sparepart [tampilkan stok tersisa], qty, harga aktual [prefill harga_standar], subtotal; tambah/hapus baris), total part + total biaya ditampilkan. Simpan → kembali ke list. Error stok tidak cukup tampil via `parseApiError`.

## Testing
- Backend per modul: CRUD + scoping perusahaan + kode unik per-perusahaan (sparepart) + tambah stok/penyesuaian (termasuk tolak stok negatif) + create/update/delete servis dgn sparepart (stok berkurang/terkoreksi/kembali, mutasi tercatat, 422 stok kurang) + snapshot jenis_perawatan sync + regresi endpoint lama.
- Frontend: tsc + eslint per file.

## Di Luar Scope
- Manajemen supplier/PO/penerimaan barang formal.
- Peringatan stok minimum/reorder point.
- Perubahan Dokumen Armada (tetap modal).
- Laporan/rekap biaya pemeliharaan agregat.
