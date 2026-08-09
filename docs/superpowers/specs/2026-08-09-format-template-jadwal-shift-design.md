# Seragamkan Format Template Import dengan Export Jadwal Shift

**Tanggal:** 2026-08-09
**Status:** Disetujui user (Opsi A)

## Masalah

Dua file Excel jadwal shift punya tampilan berbeda:

- **Export** (`jadwal-shift-YYYY-MM.xlsx`) — dibuat frontend (`PapanShift.tsx` +
  `utils/xlsx.util.ts`): baris 1 judul kuning (nama proyek, bold 14, fill `FFC000`,
  tinggi 26), baris 2 `PERIODE {BULAN TAHUN}`, baris 3 header biru (`BDD7EE`, bold,
  center, border `94A3B8`), kolom tanggal berupa angka hari.
- **Template import** (`template-jadwal-shift-*.xlsx`) — dibuat backend
  (`JadwalShiftTemplateExport`): polos tanpa styling, header di baris 1, tanggal
  berupa string `YYYY-MM-DD`.

User minta keduanya seragam dengan **format export sebagai acuan**.

## Solusi (Opsi A): style template backend meniru export, parser import dibuat toleran

Kolom template **tidak berubah** (`No SIM | Nama Supir | Shift | tanggal...`) karena
itu kontrak import (No SIM = kunci supir, kolom 3 = nama shift, sel `H` = jadwal).
Yang berubah hanya visual + ketahanan parser. Frontend tidak disentuh.

### Backend

1. **`JadwalShiftRepository` + `Contracts/JadwalShiftRepositoryInterface`** —
   method baru `namaProyek(string $idProyek): ?string` (query tabel `proyek`,
   `whereNull('dihapus_pada')`).

2. **`JadwalShiftService::templateData`** — return tambahan:
   - `nama_proyek` (dari repo, fallback `'JADWAL SHIFT SUPIR'` bila null);
   - `periode`: `'PERIODE {BULAN TAHUN}'` (bulan bahasa Indonesia, uppercase, via
     `Carbon::locale('id')->translatedFormat('F Y')`) bila `dari` & `sampai` di bulan
     sama; selain itu `'PERIODE {d/m/Y dari} - {d/m/Y sampai}'`.

3. **`JadwalShiftTemplateExport`** — restyle meniru export frontend:
   - Constructor tambah `namaProyek` dan `periode`.
   - Drop `ShouldAutoSize`; tambah `WithEvents` + `WithColumnWidths`
     (A=18, B=26, C=12, kolom tanggal=5).
   - `AfterSheet`: sisipkan 2 baris di atas; A1 = nama proyek (merge, bold 14, fill
     `FFC000`, center, tinggi baris 26); A2 = periode (merge, polos); baris 3 header
     bold + fill `BDD7EE` + center + wrap; border thin `94A3B8` untuk seluruh area
     header + data.
   - **Header tanggal**: sel ditulis sebagai nilai tanggal Excel asli
     (`Date::PHPToExcel`) dengan number format `d` — tampil angka hari (`1..31`)
     persis export, tapi nilai selnya tetap tanggal penuh sehingga import akurat
     (parser sudah menangani serial numerik via `excelToDateTimeObject`).

4. **`JadwalShiftService::importMatriks`** — deteksi baris header dinamis: cari baris
   pertama yang kolom pertamanya (trim, case-insensitive) = `no sim`; kolom tanggal
   diambil dari baris itu, data mulai baris setelahnya. Bila tidak ketemu →
   abort 422 `'Baris header (kolom "No SIM") tidak ditemukan'`.
   Baris supir yang belum diisi (kolom Shift kosong dan tidak ada sel `H`) dilewati
   diam-diam — supaya import balik template kosong tidak menghasilkan daftar `gagal`
   palsu; baris ber-`H` tapi shift kosong tetap dilaporkan gagal.
   **Kompatibel mundur**: file lama (header di baris 1, tanggal string) tetap jalan.

### Frontend

Tidak ada perubahan (export sudah jadi acuan; template diunduh sebagai binary
lewat proxy yang sudah ada).

## Perilaku Tepi

- Rentang lintas bulan (maks 62 hari): angka hari di header bisa berulang secara
  visual, tapi nilai sel tetap tanggal penuh — import tetap benar. Diterima.
- User mengetik ulang header tanggal manual sebagai teks (mis. `2026-08-10`) —
  tetap terbaca (fallback parse Carbon).
- File tanpa baris `No SIM` sama sekali → 422 dengan pesan jelas.

## Testing (PHPUnit, extend `JadwalShiftImportTest`)

1. Import file dengan 2 baris judul di atas header (simulasi format baru) → sukses,
   jadwal terbuat.
2. Import dengan header tanggal berupa nilai tanggal/serial Excel → terbaca benar.
3. Roundtrip: unduh template dari endpoint, simpan, import balik → 200 tanpa 422
   (sukses 0 karena sel kosong).
4. Test existing (format lama) tetap lolos tanpa perubahan.

## Di Luar Cakupan

- Penyeragaman nama file unduhan.
- Mengubah tampilan export frontend (sudah jadi acuan).
- Gaya `DenganGayaLaporan` (dipakai laporan lain, bukan format jadwal shift).
