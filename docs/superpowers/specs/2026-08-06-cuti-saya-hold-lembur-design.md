# Cuti Mandiri Staff Mobile + Hold Lembur Absen Mobile

Tanggal: 2026-08-06
Status: disetujui user (chat)

## Ringkasan

Dua pekerjaan yang disetujui bersamaan: (A) **hold lembur** — jam pulang dari self check-out mobile tidak menghasilkan lembur payroll sampai disentuh admin; (B) **pengajuan cuti mandiri** dari mobile staff: tab ke-4 "Cuti" (ajukan, riwayat + status, saldo, batalkan yang menunggu).

## Keputusan

| Hal | Keputusan |
|---|---|
| Hold lembur | Flag `pulang_mandiri` (tinyint default 0) di `absensi`; `absenPulang` mobile set 1; query lembur exclude flag 1; sentuhan admin (`simpanHarian`) reset 0 = terverifikasi & dihitung |
| Cuti scope | Ajukan + riwayat/status + saldo tahun berjalan + batalkan (hanya milik sendiri & `menunggu`) |
| Approval cuti | Tetap admin/web (status awal `menunggu`) |
| Penempatan mobile | Tab ke-4: Beranda, Absensi, **Cuti**, Profil |
| Izin route | Group `izin:karyawan` existing modul Cuti; guard `pengguna.id_karyawan` null → 422 |
| Dropdown jenis | Pakai `GET /jenis-cuti` existing (filter `aktif` di mobile) |

## A. Hold Lembur (backend)

- Migration: kolom `pulang_mandiri` tinyint default 0 di `absensi` (after `alamat_pulang`).
- `AbsensiService::absenPulang` → upsert menyertakan `'pulang_mandiri' => 1`.
- `AbsensiService::simpanHarian` → upsert menyertakan `'pulang_mandiri' => 0` (input/koreksi admin = verifikasi).
- `AbsensiRepository::jamPulangDalamRentang` → tambah `->where('pulang_mandiri', 0)` — satu-satunya pintu hitung lembur (dipakai rekap absensi & payroll).
- Test: self check-out jam 20:00 → `lembur_menit` 0 di rekap; input admin jam 20:00 → lembur terhitung; admin menimpa baris mandiri → lembur terhitung.

## B. Endpoint Cuti Saya (backend, modul Cuti)

```
GET  /api/v1/pengajuan-cuti/saya            → list pengajuan milik karyawan login (+nama_jenis), terbaru dulu
POST /api/v1/pengajuan-cuti/saya            → body: id_jenis_cuti, tanggal_mulai, tanggal_selesai, alasan?
POST /api/v1/pengajuan-cuti/saya/{id}/batalkan → hanya milik sendiri + status menunggu
GET  /api/v1/saldo-cuti/saya                → saldoInfo tahun berjalan karyawan login
```

- `createPengajuanSaya` membungkus `createPengajuan` existing dengan `id_karyawan` dari token (`id_supir` null) — semua validasi existing (jenis valid, overlap 422, hitung `jumlah_hari`) otomatis berlaku.
- `batalkanSaya`: pengajuan bukan milik sendiri → 404; status bukan `menunggu` → 422; set `dibatalkan` (tanpa rollback ledger — belum disetujui).
- `saldoSaya`: `saldoInfo(idPerusahaan, idKaryawan, null, tahun berjalan)` — `{tahun, jatah 12, penyesuaian, terpakai, sisa, riwayat}`.
- Guard `pastikanKaryawan` (422 "Akun Anda tidak tertaut dengan data karyawan") seperti modul Absensi.
- Repo baru: `pengajuanByKaryawan(string $idKaryawan): array` (join nama jenis, urut terbaru).
- Route didaftarkan SEBELUM route `pengajuan-cuti/{id}/...` existing.

## C. Mobile — Tab Cuti (`lib/features/karyawan/cuti/`)

- File: `cuti_models.dart` (PengajuanCutiSaya, SaldoCutiSaya, JenisCuti — fromJson, boolean 0/1 aware), `karyawan_cuti_repository.dart`, `karyawan_cuti_controller.dart`, `karyawan_cuti_view.dart` (+ form sebagai bottom sheet di file view atau file widget terpisah bila >1000 baris).
- View: kartu saldo gradien (`gradHero`): "Sisa Cuti {tahun}" besar + detail jatah/terpakai/penyesuaian; tombol "Ajukan Cuti" (`TmnGradientButton`) buka bottom sheet form: dropdown jenis cuti (aktif), date picker mulai & selesai (validasi selesai ≥ mulai), alasan multiline, submit; list riwayat: jenis, rentang tanggal, jumlah hari, badge status warna (menunggu `statusSebagian`, disetujui `statusLunas`, ditolak `statusBelum`, dibatalkan `statusBatal`), alasan/catatan proses bila ada; item `menunggu` punya tombol Batalkan + dialog konfirmasi.
- Bottom nav 4 tab, icon Cuti: `Icons.event_busy_rounded`.
- Pull-to-refresh (saldo + riwayat). Error via `AppSnackbar`. Sukses ajukan/batal → refresh list + saldo.

## Testing

- Backend TDD: `LemburHoldTest` (3 skenario A) + `CutiSayaTest` (ajukan sukses & muncul di riwayat; overlap 422; batalkan milik sendiri sukses; batalkan milik orang lain 404; batalkan non-menunggu 422; saldo benar setelah penyesuaian/approval; akun tanpa karyawan 422) + suite penuh.
- Mobile: `flutter analyze` bersih di file fitur.

## Di Luar Scope

- Approval cuti dari mobile (tetap web), cuti supir mobile, notifikasi push.
- UI web menampilkan flag `pulang_mandiri` (bisa menyusul).
