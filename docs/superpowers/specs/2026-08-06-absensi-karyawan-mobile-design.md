# Absensi Mandiri Karyawan (Mobile Staff) — Check-in/out + Lokasi

Tanggal: 2026-08-06
Status: disetujui user (chat)

## Ringkasan

Role **karyawan** (staff) di mobile mendapat tab **Absensi** baru: jam digital live, tombol Masuk/Pulang, dan kartu lokasi GPS. Backend mendapat 3 endpoint absen mandiri yang menulis ke tabel `absensi` existing — data langsung tersambung ke rekap HR, lembur, dan payroll tanpa kerja tambahan.

## Keputusan Desain (hasil brainstorming)

| Hal | Keputusan |
|---|---|
| Lokasi GPS | Ditampilkan di app DAN disimpan ke server (bukti kehadiran) |
| Lokasi wajib? | Best-effort — GPS gagal/ditolak → absen tetap bisa, lokasi null |
| Status masuk | Otomatis `hadir`/`terlambat` dari pengaturan absensi existing (jam masuk + toleransi) |
| Aturan | Masuk sekali/hari (dobel → 422); Pulang wajib sudah masuk, sekali (dobel → 422) |
| Penempatan mobile | Tab ketiga bottom nav karyawan: Beranda, **Absensi**, Profil |
| Izin route | Group `izin:karyawan` existing di `AbsensiServiceProvider` (role staff mobile terbukti punya — sudah mengakses `/karyawan`); guard tambahan: `pengguna.id_karyawan` null → 422 |

## Backend

### Migration

`add_lokasi_to_absensi_table`: 6 kolom nullable di `absensi` — `latitude_masuk` decimal(10,7), `longitude_masuk` decimal(10,7), `alamat_masuk` varchar(500), `latitude_pulang`, `longitude_pulang`, `alamat_pulang` (tipe sama).

### Endpoint (group `izin:karyawan` di `AbsensiServiceProvider`)

```
GET  /api/v1/absensi/saya/hari-ini   → absensi hari ini milik karyawan login (null bila belum ada)
POST /api/v1/absensi/saya/masuk      → body: latitude?, longitude?, alamat? (semua nullable)
POST /api/v1/absensi/saya/pulang     → body sama
```

- Identitas: `auth()->user()->id_karyawan` (kolom di tabel `pengguna`, sudah ada). Null → 422 "Akun Anda tidak tertaut dengan data karyawan".
- Masuk: tolak bila `jam_masuk` hari ini sudah terisi; status = `terlambat` bila waktu sekarang > (jam masuk pengaturan + toleransi menit), selain itu `hadir`; simpan `jam_masuk` + lokasi masuk via `AbsensiRepository::upsert` existing.
- Pulang: tolak bila belum masuk atau `jam_pulang` sudah terisi; simpan `jam_pulang` + lokasi pulang.
- Semua logika di `AbsensiService` (method `absensiSaya`, `absenMasuk`, `absenPulang`); query di `AbsensiRepository` (+method baru `findByKaryawanTanggal`); validasi di Controller.
- Timezone `APP_TIMEZONE=Asia/Jakarta` (sudah dikonfigurasi) — `now()` otomatis WIB.
- Input admin massal (`simpanHarian`) tidak berubah dan tetap bisa menimpa/melengkapi (upsert baris yang sama).

## Mobile (`lib/features/karyawan/absensi/`)

- File: `absensi_saya_model.dart` (fromJson, konversi `0/1`-aware bila perlu), `karyawan_absensi_repository.dart` (semua Dio, `DioClient.karyawan`, DioException → AppException), `karyawan_absensi_controller.dart` (GetX: state absensi, jam live via `Timer.periodic` 1 detik — cancel di `onClose`, lokasi via geolocator + reverse-geocode via geocoding, submit masuk/pulang), `karyawan_absensi_view.dart`.
- UI mengikuti referensi screenshot dengan gaya app: kartu hero `TmnColors.gradHero` radius besar (tanggal Indonesia manual array hari/bulan — tanpa initializeDateFormatting, jam `HH:mm:ss` besar, kolom Masuk/Pulang menampilkan jam tercatat atau "—"), kartu putih "Lokasi Anda" (icon pin, alamat/status GPS, tombol refresh), dua tombol sejajar Masuk (gradien) & Pulang (outline navy) dengan disabled state mengikuti progres absen.
- Tab ketiga di `KaryawanMainView` (`IndexedStack`), icon `Icons.fingerprint_rounded`, label "Absensi".
- Dependency baru: `geolocator`, `geocoding`; permission `ACCESS_FINE_LOCATION` + `ACCESS_COARSE_LOCATION` di AndroidManifest.
- GPS gagal/izin ditolak → kartu lokasi menampilkan pesan, tombol absen tetap aktif (kirim lokasi null).

## Testing

- Backend TDD (`AbsensiSayaTest`): masuk mencatat jam+lokasi+status `hadir`; skenario `terlambat` via `Carbon::setTestNow`; masuk dobel 422; pulang tanpa masuk 422; pulang normal + dobel 422; akun tanpa `id_karyawan` 422; data muncul via `GET /absensi/saya/hari-ini`.
- Mobile: `flutter analyze` bersih; uji visual/build oleh user.

## Di Luar Scope

- Absensi supir (fitur existing, tidak disentuh).
- Geofencing/validasi radius kantor; foto selfie absen.
- Tampilan lokasi absen di web admin (kolom tersimpan, UI web menyusul bila diminta).
