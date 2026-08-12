# Fase 1 — Penagihan Trip ke Klien

**Tanggal:** 2026-08-11
**Status:** Disetujui user (desain rekap + generate draft otomatis)
**Konteks:** Lanjutan Fase 0 (mekanisme vendor). Laporan perjalanan jadi bahan
penagihan: trip selesai ber-laporan → draft faktur klien otomatis dari tarif rute.

## Masalah

Faktur klien sekarang diisi manual (deskripsi + qty + harga bebas) tanpa tautan ke
trip — rawan trip tertagih dobel atau terlewat, dan tidak ada layar yang menunjukkan
trip mana saja yang siap ditagihkan.

## Solusi

### Backend — modul baru `PenagihanTrip`

**Endpoint 1 — daftar trip siap tagih**
`GET /api/v1/penagihan-trip?id_proyek=&dari=&sampai=`

Kriteria trip masuk daftar:
- status `selesai` dan **punya laporan perjalanan** (bukti kerja);
- milik proyek terpilih (periode difilter dari `DATE(COALESCE(jk.waktu_berangkat, trip.dibuat_pada))`);
- **belum terkait faktur aktif**: tidak ada baris `faktur_trip` hidup yang
  faktur-nya berstatus ≠ `batal` dan belum dihapus.

Respons per trip: `id_trip`, `tanggal`, `rute` (nama master ?? teks jadwal),
`nopol`, `supir_nama`, `sumber`, `jarak_tempuh_km` (laporan), dan **`tarif`**:
`{ id_tarif_rute, harga }` hasil `TarifRuteService::resolusi(idPerusahaan,
id_rute, id_jenis_kendaraan_armada, id_klien_proyek, tanggal_trip)` — `null` bila
jadwal tanpa `id_rute`, armada tanpa `id_jenis_kendaraan` (termasuk semua armada
vendor — `armada_vendor` tidak punya relasi jenis kendaraan), atau tarif berlaku
tidak ditemukan. Trip ber-tarif `null` ditandai `bisa_ditagih: false`.

**Endpoint 2 — generate draft faktur**
`POST /api/v1/penagihan-trip/faktur` body
`{ id_proyek, trip_ids[], tanggal_faktur, jatuh_tempo? }`

- Validasi dalam transaksi (lock): semua trip milik perusahaan + proyek itu,
  memenuhi kriteria daftar di atas, dan tarif resolvable — satu saja gagal → 422
  dengan sebab jelas, tidak ada yang tersimpan.
- Item faktur di-grup per `(id_rute, harga tarif)`:
  deskripsi `"Rute {nama} — {N} rit"`, `qty = N`, `harga_satuan = harga tarif`.
- Faktur dibuat via `FakturService::create`: status `draft`, `id_proyek`,
  `id_klien` proyek, `nomor_faktur` auto `FK-{Ym}-{NNNN}` (generator baru
  `FakturRepository::nomorBerikutnya`, lockForUpdate, unik per perusahaan; nomor
  tetap bisa diedit user di draft).
- Tautan dicatat di tabel baru **`faktur_trip`** (lihat Data).
- Respons: faktur lengkap (frontend redirect ke detail faktur).

**Anti tagih dobel:** keunikan dijaga level aplikasi (cek + lock dalam
transaksi). Tabel `faktur_trip` TIDAK memakai unique `id_trip` — saat faktur
dibatalkan/dihapus, tautannya otomatis dianggap tidak aktif lewat join status
faktur, sehingga trip kembali muncul di daftar tanpa perlu membersihkan baris.

### Data — migration `faktur_trip`

```
faktur_trip: id_faktur_trip CHAR(36) PK, id_faktur CHAR(36) index,
             id_trip CHAR(36) index, audit columns
```

### Frontend — halaman `/penagihan-trip` (menu Keuangan)

- Filter: proyek (persist `?proyek=` + localStorage, pola penugasan) + rentang
  tanggal (default bulan berjalan).
- Tabel trip: checkbox per baris (disabled + badge "Tarif belum diatur" bila
  `bisa_ditagih: false`), kolom tanggal, rute, nopol, supir, sumber, jarak,
  tarif (Rp).
- Bar aksi: "{N} trip dipilih — estimasi Rp X" + tombol **Buat Draft Faktur**
  (solid, `HiPlusCircle`) → dialog: tanggal faktur (default hari ini), jatuh
  tempo opsional → POST → toast sukses → redirect `ROUTES.FAKTUR_DETAIL(id)`.
- Menu sidebar: item "Penagihan Trip" di grup Keuangan (sebelum Faktur),
  authority `['keuangan', 'manager', 'superadmin', 'admin']`.

## Perilaku Tepi

- Faktur hasil generate tetap draft biasa: item bisa diedit/ditambah manual,
  nomor bisa diganti — alur faktur existing tidak berubah.
- Faktur `batal` / dihapus → trip-nya otomatis bisa ditagihkan lagi.
- Trip vendor (armada vendor) selalu `bisa_ditagih: false` di fase ini — tarif
  butuh jenis kendaraan; pemetaan jenis kendaraan armada vendor menyusul.
- Dua user generate bersamaan → transaksi + lock; yang kalah dapat 422 "trip
  sudah difakturkan".

## Testing (PHPUnit, `PenagihanTripTest` baru)

- Daftar: trip selesai ber-laporan muncul; tanpa laporan / belum selesai tidak;
  resolusi tarif klien-spesifik menang atas umum; trip tanpa tarif
  `bisa_ditagih: false`.
- Generate: draft faktur dibuat (grup item, qty, total, `faktur_trip` terisi,
  nomor `FK-`); trip hilang dari daftar setelah generate; faktur di-`batal` →
  muncul lagi; trip tanpa tarif / sudah difakturkan → 422 tanpa efek samping.

## Di Luar Cakupan

- Konsolidasi vendor (Fase 2).
- Pemetaan jenis kendaraan untuk armada vendor.
- PPN/pajak di faktur (mengikuti struktur faktur existing yang belum berpajak).
- Harga manual untuk trip tanpa tarif.
