# Simpan Path Relatif untuk Berkas Upload (tanpa URL absolut di DB)

Tanggal: 2026-08-05
Status: disetujui user (chat) — instruksi langsung: "data url-nya dibuat saja nama file dan foldernya tanpa harus simpan http://"

## Latar

Semua modul upload menyimpan URL absolut (`APP_URL/storage/...`) permanen di DB saat upload. Akibatnya nilai `APP_URL` saat upload terbakar ke data — di server muncul bug unduhan mengarah ke `localhost:4001`. Perbaikan data lama dilakukan **user sendiri** via SQL UPDATE (di luar scope). Spec ini menghilangkan akar masalahnya untuk seterusnya.

## Keputusan

| Hal | Keputusan |
|---|---|
| Yang disimpan di DB | Path relatif `folder/namafile.ext` (hasil `store()`), kolom existing, tanpa migration |
| Resolusi URL | Saat dibaca (Resource/Service), via helper — `APP_URL` selalu nilai terkini |
| Nilai lama `http(s)://...` | Pass-through apa adanya (hybrid) — kompatibel dengan data lama & hasil UPDATE user |
| Disk | Tetap `public` (lokal). Saat S3 baru siap ([[s3-tertunda-desain-siap]]), hanya helper yang berubah |
| Frontend/Mobile | Tidak berubah — API tetap mengembalikan URL lengkap siap pakai |

## Helper — `app/Support/PenyimpananBerkas.php`

- `simpan(UploadedFile $file, string $folder): string` → `$file->store($folder, 'public')`, return path relatif.
- `url(?string $nilai): ?string` → `null`/kosong → `null`; berawalan `http://`/`https://` → apa adanya; selain itu → `Storage::disk('public')->url($nilai)`.

## Titik perubahan (9 modul)

Upload (simpan path, bukan URL) → semua ganti pola `store(...,'public')` + `disk('public')->url()` menjadi `PenyimpananBerkas::simpan()`:

| Modul | Service (baris kini) | Kolom | Titik keluaran |
|---|---|---|---|
| Armada (foto) | `ArmadaService.php:105-109` (`simpanFoto`) | `url_foto` | `ArmadaResource.php:34` |
| DokumenArmada | `DokumenArmadaService.php:56-57, 66-67` | `url_file` | `DokumenArmadaResource.php:21` |
| DokumenKaryawan | `DokumenKaryawanService.php:45-46, 58-59` | `url_file` | `DokumenKaryawanResource.php:19` |
| DokumenVendor | `DokumenVendorService.php:55-56, 65-66` | `url_file` | `DokumenVendorResource.php:19` |
| KontrakKaryawan | `KontrakKaryawanService.php:30-31, 46-47` | `url_file` | `KontrakKaryawanResource.php:21` |
| PembayaranVendor | `PembayaranVendorService.php:31-32` | `url_bukti` | `PembayaranVendorResource.php:21` |
| LaporanPerjalanan (foto) | `LaporanPerjalananService.php:202-204` | `url_file` | `FotoLaporanResource.php:16` |
| PerawatanArmada (bukti) | `PerawatanArmadaService.php:174-177` | `url_file` | array di `PerawatanArmadaService.php:161` |
| PembelianSparepart (bukti) | `PembelianSparepartService.php:171-174` | `url_file` | konsumen `PembelianSparepartRepository.php:56` (array bukti di Service) |

Titik keluaran membungkus nilai dengan `PenyimpananBerkas::url(...)`.

Tidak ada blade/PDF yang memakai kolom file (sudah diverifikasi grep `resources/views`).

## Testing (TDD)

- Test helper: `simpan` menghasilkan path tanpa `http`, `url` menangani null / legacy http / path.
- Per modul: test upload existing disesuaikan + assertion baru — nilai tersimpan di DB **tidak** berawalan `http`, respons API tetap URL lengkap (`/storage/...`).
- Suite penuh backend hijau.

## Di Luar Scope

- Update data lama di DB server (dikerjakan user).
- Penghapusan file fisik saat replace/delete (parity dengan perilaku sekarang).
- Migrasi ke S3 (menyusul, tinggal ganti isi helper).
