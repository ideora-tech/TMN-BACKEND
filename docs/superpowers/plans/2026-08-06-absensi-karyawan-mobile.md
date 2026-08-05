# Absensi Mandiri Karyawan Mobile — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tab Absensi di mobile karyawan (jam live, Masuk/Pulang, lokasi GPS) dengan 3 endpoint absen mandiri backend yang menulis ke tabel `absensi` existing.

**Architecture:** Backend: migration kolom lokasi + method service/repo baru di modul Absensi, route di group `izin:karyawan` existing. Mobile: fitur GetX baru `karyawan/absensi` + tab ketiga di `KaryawanMainView`, gaya `TmnColors`.

**Tech Stack:** Laravel 11; Flutter 3.8 + GetX + Dio; geolocator + geocoding (baru).

**Spec:** `docs/superpowers/specs/2026-08-06-absensi-karyawan-mobile-design.md`

## Global Constraints

- **DILARANG perintah git yang mengubah state** — user commit manual; akhiri task dengan daftar file berubah.
- Backend test: `vendor/bin/phpunit --filter=...` (JANGAN `php artisan test`). Mobile: HANYA `flutter pub get` + `flutter analyze` — DILARANG `flutter run`/`flutter build`.
- Jangan tulis komentar penjelas di kode.
- Backend: Eloquent/query builder hanya di `*Repository.php`; Service throw HTTP exception; respons ikuti `ApiResponse`. Mobile: semua Dio hanya di `*_repository.dart`; `DioException` → `AppException`; `Obx` hanya di widget reaktif; file ≤1000 baris.
- Working dir backend: `D:\PROJECT-TMN\TMN-TRANSPORT-BACKEND`; mobile: `D:\PROJECT-TMN\TMN-TRANSPORT-MOBILE`.

---

### Task 1: Backend — migration lokasi + endpoint absensi saya

**Files:**
- Create: `database/migrations/2026_08_06_000001_add_lokasi_to_absensi_table.php`
- Modify: `app/Modules/Absensi/Contracts/AbsensiRepositoryInterface.php`, `app/Modules/Absensi/AbsensiRepository.php` (tambah `findByKaryawanTanggal`)
- Modify: `app/Modules/Absensi/AbsensiService.php` (3 method), `app/Modules/Absensi/AbsensiController.php` (3 method), `app/Modules/Absensi/AbsensiServiceProvider.php` (3 route)
- Test (create): `tests/Feature/AbsensiSayaTest.php`

**Interfaces:**
- Produces: `GET /api/v1/absensi/saya/hari-ini` → `data` = objek absensi (`tanggal, status, jam_masuk, jam_pulang, latitude_masuk, longitude_masuk, alamat_masuk, latitude_pulang, longitude_pulang, alamat_pulang`) atau `null`; `POST /api/v1/absensi/saya/masuk` dan `.../pulang` body `{latitude?, longitude?, alamat?}` → objek absensi terbaru. Task 2 (mobile) memakai kontrak ini.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->decimal('latitude_masuk', 10, 7)->nullable()->after('jam_masuk');
            $table->decimal('longitude_masuk', 10, 7)->nullable()->after('latitude_masuk');
            $table->string('alamat_masuk', 500)->nullable()->after('longitude_masuk');
            $table->decimal('latitude_pulang', 10, 7)->nullable()->after('jam_pulang');
            $table->decimal('longitude_pulang', 10, 7)->nullable()->after('latitude_pulang');
            $table->string('alamat_pulang', 500)->nullable()->after('longitude_pulang');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn(['latitude_masuk', 'longitude_masuk', 'alamat_masuk', 'latitude_pulang', 'longitude_pulang', 'alamat_pulang']);
        });
    }
};
```

Jalankan `php artisan migrate` agar test bisa jalan.

- [ ] **Step 2: Tulis test (RED)**

Buat `tests/Feature/AbsensiSayaTest.php`. Baca dulu `tests/TestCase.php` dan `tests/Feature/AuthKaryawanTest.php` untuk pola `actingAsRole` + cara menautkan `pengguna.id_karyawan`. Kerangka wajib (sesuaikan helper auth dengan pola aktual TestCase):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AbsensiSayaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loginSebagaiKaryawan(): string
    {
        $user = $this->actingAsRole('SUPERADMIN');
        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan' => $idKaryawan, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nik' => 'NIK-ABSSAYA-' . Str::random(4), 'nama_karyawan' => 'Staff Absen', 'aktif' => 1,
            'dibuat_pada' => now(),
        ]);
        DB::table('pengguna')->where('id_pengguna', $user->id_pengguna)->update(['id_karyawan' => $idKaryawan]);
        return $idKaryawan;
    }

    private function setPengaturan(): void
    {
        $this->putJson('/api/v1/absensi/pengaturan', [
            'jam_masuk' => '08:00', 'jam_pulang' => '17:00', 'toleransi_terlambat_menit' => 15,
        ])->assertStatus(200);
    }

    public function test_absen_masuk_mencatat_jam_lokasi_dan_status_hadir(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $res = $this->postJson('/api/v1/absensi/saya/masuk', [
            'latitude' => -6.2000001, 'longitude' => 106.8000001, 'alamat' => 'Jl. Kantor No. 1, Bekasi',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'hadir')
            ->assertJsonPath('data.alamat_masuk', 'Jl. Kantor No. 1, Bekasi');

        $baris = DB::table('absensi')->where('id_karyawan', $idKaryawan)->where('tanggal', '2026-08-06')->first();
        $this->assertNotNull($baris);
        $this->assertSame('07:55:00', $baris->jam_masuk);
        $this->assertEquals(-6.2000001, (float) $baris->latitude_masuk);
    }

    public function test_absen_masuk_lewat_toleransi_berstatus_terlambat(): void
    {
        $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 08:20:00'));

        $this->postJson('/api/v1/absensi/saya/masuk', [])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'terlambat');
    }

    public function test_absen_masuk_dua_kali_ditolak(): void
    {
        $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(422);
    }

    public function test_absen_pulang_butuh_masuk_dulu_dan_sekali_saja(): void
    {
        $idKaryawan = $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(422);

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        Carbon::setTestNow(Carbon::parse('2026-08-06 17:05:00'));
        $this->postJson('/api/v1/absensi/saya/pulang', ['alamat' => 'Jl. Pulang'])
            ->assertStatus(200)
            ->assertJsonPath('data.alamat_pulang', 'Jl. Pulang');

        $baris = DB::table('absensi')->where('id_karyawan', $idKaryawan)->first();
        $this->assertSame('17:05:00', $baris->jam_pulang);

        $this->postJson('/api/v1/absensi/saya/pulang', [])->assertStatus(422);
    }

    public function test_hari_ini_mengembalikan_absensi_atau_null(): void
    {
        $this->loginSebagaiKaryawan();
        $this->setPengaturan();
        Carbon::setTestNow(Carbon::parse('2026-08-06 07:55:00'));

        $this->getJson('/api/v1/absensi/saya/hari-ini')->assertStatus(200)->assertJsonPath('data', null);

        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(200);
        $this->getJson('/api/v1/absensi/saya/hari-ini')->assertStatus(200)->assertJsonPath('data.status', 'hadir');
    }

    public function test_akun_tanpa_tautan_karyawan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->postJson('/api/v1/absensi/saya/masuk', [])->assertStatus(422);
    }
}
```

Catatan: bila `actingAsRole` tidak mengembalikan objek user, ambil user via `auth()->user()` setelah acting — sesuaikan dengan pola aktual `TestCase` (jangan ubah `TestCase`). Bila pengguna seeded `actingAsRole` sudah punya `id_karyawan`, set eksplisit ke id karyawan baru tetap benar.

- [ ] **Step 3: Jalankan test, pastikan gagal 404** — `vendor/bin/phpunit --filter=AbsensiSayaTest` → route belum ada.

- [ ] **Step 4: Repo + interface**

Interface tambah: `public function findByKaryawanTanggal(string $idKaryawan, string $tanggal): ?object;` (docblock 1 baris boleh). Implementasi di `AbsensiRepository` meniru gaya query method existing di file yang sama (tabel `absensi`, filter `id_karyawan` + `tanggal`, `whereNull('dihapus_pada')` bila pola existing memakainya, `->first()`).

- [ ] **Step 5: Service — 3 method**

Tambahkan di `AbsensiService` (perhatikan `pengaturan()` dan `$this->repo->upsert($idPerusahaan, $idKaryawan, $tanggal, array $data)` sudah ada):

```php
    public function absensiSaya(string $idPerusahaan, ?string $idKaryawan): ?object
    {
        $this->pastikanKaryawan($idKaryawan);
        return $this->repo->findByKaryawanTanggal($idKaryawan, now()->toDateString());
    }

    public function absenMasuk(string $idPerusahaan, ?string $idKaryawan, array $lokasi): object
    {
        $this->pastikanKaryawan($idKaryawan);
        $tanggal = now()->toDateString();

        $existing = $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
        if ($existing !== null && $existing->jam_masuk !== null) {
            abort(422, 'Sudah absen masuk hari ini');
        }

        $pengaturan = $this->pengaturan($idPerusahaan);
        $batas = Carbon::parse($tanggal . ' ' . $pengaturan['jam_masuk'])
            ->addMinutes($pengaturan['toleransi_terlambat_menit']);

        $this->repo->upsert($idPerusahaan, $idKaryawan, $tanggal, [
            'status'          => now()->greaterThan($batas) ? 'terlambat' : 'hadir',
            'jam_masuk'       => now()->format('H:i:s'),
            'latitude_masuk'  => $lokasi['latitude'] ?? null,
            'longitude_masuk' => $lokasi['longitude'] ?? null,
            'alamat_masuk'    => $lokasi['alamat'] ?? null,
        ]);

        return $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
    }

    public function absenPulang(string $idPerusahaan, ?string $idKaryawan, array $lokasi): object
    {
        $this->pastikanKaryawan($idKaryawan);
        $tanggal = now()->toDateString();

        $existing = $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
        if ($existing === null || $existing->jam_masuk === null) {
            abort(422, 'Belum absen masuk hari ini');
        }
        if ($existing->jam_pulang !== null) {
            abort(422, 'Sudah absen pulang hari ini');
        }

        $this->repo->upsert($idPerusahaan, $idKaryawan, $tanggal, [
            'jam_pulang'       => now()->format('H:i:s'),
            'latitude_pulang'  => $lokasi['latitude'] ?? null,
            'longitude_pulang' => $lokasi['longitude'] ?? null,
            'alamat_pulang'    => $lokasi['alamat'] ?? null,
        ]);

        return $this->repo->findByKaryawanTanggal($idKaryawan, $tanggal);
    }

    private function pastikanKaryawan(?string $idKaryawan): void
    {
        if ($idKaryawan === null || $idKaryawan === '') {
            abort(422, 'Akun Anda tidak tertaut dengan data karyawan');
        }
    }
```

PENTING: cek dulu isi `upsert` existing — bila `upsert` menimpa `status` dari `$data` saja (tidak reset kolom lain), pemanggilan pulang tanpa key `status` harus MEMPERTAHANKAN status lama. Bila implementasi existing menuntut key tertentu, sesuaikan minimal (mis. sertakan `status` existing). Import `Carbon` bila belum.

- [ ] **Step 6: Controller — 3 method**

```php
    public function absensiSaya(Request $request): JsonResponse
    {
        $user = $request->user();
        return ApiResponse::success($this->service->absensiSaya((string) $user->id_perusahaan, $user->id_karyawan));
    }

    public function absenMasuk(Request $request): JsonResponse
    {
        $user = $request->user();
        $lokasi = $request->validate([
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'alamat'    => ['nullable', 'string', 'max:500'],
        ]);
        return ApiResponse::success($this->service->absenMasuk((string) $user->id_perusahaan, $user->id_karyawan, $lokasi), 'Absen masuk tercatat');
    }

    public function absenPulang(Request $request): JsonResponse
    {
        $user = $request->user();
        $lokasi = $request->validate([
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'alamat'    => ['nullable', 'string', 'max:500'],
        ]);
        return ApiResponse::success($this->service->absenPulang((string) $user->id_perusahaan, $user->id_karyawan, $lokasi), 'Absen pulang tercatat');
    }
```

- [ ] **Step 7: Route** — di group existing `AbsensiServiceProvider`, tambah SEBELUM route lain:

```php
                Route::get('absensi/saya/hari-ini', [AbsensiController::class, 'absensiSaya']);
                Route::post('absensi/saya/masuk', [AbsensiController::class, 'absenMasuk']);
                Route::post('absensi/saya/pulang', [AbsensiController::class, 'absenPulang']);
```

- [ ] **Step 8: GREEN + suite penuh** — `vendor/bin/phpunit --filter=AbsensiSayaTest` semua hijau, lalu `vendor/bin/phpunit` (regresi nol — perhatikan test Absensi/Payroll existing tetap hijau).

- [ ] **Step 9: Laporkan file berubah (tanpa commit)**

---

### Task 2: Mobile — tab Absensi karyawan

**Files (semua di `D:\PROJECT-TMN\TMN-TRANSPORT-MOBILE`):**
- Modify: `pubspec.yaml` (tambah `geolocator: ^13.0.1`, `geocoding: ^3.0.0` di dependencies)
- Modify: `android/app/src/main/AndroidManifest.xml` (tambah `<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />` dan `ACCESS_COARSE_LOCATION` sejajar permission existing)
- Create: `lib/features/karyawan/absensi/absensi_saya_model.dart`
- Create: `lib/features/karyawan/absensi/karyawan_absensi_repository.dart`
- Create: `lib/features/karyawan/absensi/karyawan_absensi_controller.dart`
- Create: `lib/features/karyawan/absensi/karyawan_absensi_view.dart`
- Modify: `lib/features/karyawan/dashboard/views/karyawan_main_view.dart` (tab ketiga di tengah)

**Interfaces:**
- Consumes: endpoint Task 1 via `DioClient.karyawan` (respons `{success, message, data}`).

- [ ] **Step 1: pubspec + manifest + pub get**

Tambah dua dependency, dua permission, lalu jalankan `flutter pub get` (HANYA pub get — dilarang run/build).

- [ ] **Step 2: Model**

```dart
class AbsensiSaya {
  final String? status;
  final String? jamMasuk;
  final String? jamPulang;
  final String? alamatMasuk;
  final String? alamatPulang;
  final String? tanggal;

  AbsensiSaya({this.status, this.jamMasuk, this.jamPulang, this.alamatMasuk, this.alamatPulang, this.tanggal});

  factory AbsensiSaya.fromJson(Map<String, dynamic> json) => AbsensiSaya(
        status: json['status'] as String?,
        jamMasuk: json['jam_masuk'] as String?,
        jamPulang: json['jam_pulang'] as String?,
        alamatMasuk: json['alamat_masuk'] as String?,
        alamatPulang: json['alamat_pulang'] as String?,
        tanggal: json['tanggal'] as String?,
      );

  bool get sudahMasuk => jamMasuk != null;
  bool get sudahPulang => jamPulang != null;
}
```

- [ ] **Step 3: Repository**

```dart
import 'package:dio/dio.dart';
import '../../../core/dio_client.dart';
import 'absensi_saya_model.dart';

class KaryawanAbsensiRepository {
  final _dio = DioClient.karyawan;

  Future<AbsensiSaya?> hariIni() async {
    try {
      final res = await _dio.get('/absensi/saya/hari-ini');
      final data = res.data['data'];
      return data == null ? null : AbsensiSaya.fromJson(data as Map<String, dynamic>);
    } on DioException catch (e) {
      throw dioToApp(e);
    }
  }

  Future<AbsensiSaya> masuk({double? latitude, double? longitude, String? alamat}) =>
      _absen('/absensi/saya/masuk', latitude, longitude, alamat);

  Future<AbsensiSaya> pulang({double? latitude, double? longitude, String? alamat}) =>
      _absen('/absensi/saya/pulang', latitude, longitude, alamat);

  Future<AbsensiSaya> _absen(String path, double? latitude, double? longitude, String? alamat) async {
    try {
      final res = await _dio.post(path, data: {
        if (latitude != null) 'latitude': latitude,
        if (longitude != null) 'longitude': longitude,
        if (alamat != null) 'alamat': alamat,
      });
      return AbsensiSaya.fromJson(res.data['data'] as Map<String, dynamic>);
    } on DioException catch (e) {
      throw dioToApp(e);
    }
  }
}
```

Catatan: cek nama helper konversi error di `dio_client.dart` (`dioToApp` dipakai `karyawan_laporan_repository.dart`) — ikuti yang aktual.

- [ ] **Step 4: Controller**

```dart
import 'dart:async';
import 'package:geocoding/geocoding.dart';
import 'package:geolocator/geolocator.dart';
import 'package:get/get.dart';
import '../../../core/app_exception.dart';
import '../../../core/tmn_theme.dart';
import 'absensi_saya_model.dart';
import 'karyawan_absensi_repository.dart';

class KaryawanAbsensiController extends GetxController {
  final _repo = KaryawanAbsensiRepository();

  final absensi = Rxn<AbsensiSaya>();
  final loading = true.obs;
  final submitting = false.obs;
  final jam = ''.obs;
  final alamat = ''.obs;
  final lokasiLoading = false.obs;
  double? _latitude;
  double? _longitude;
  Timer? _ticker;

  @override
  void onInit() {
    super.onInit();
    _mulaiJam();
    load();
    ambilLokasi();
  }

  @override
  void onClose() {
    _ticker?.cancel();
    super.onClose();
  }

  void _mulaiJam() {
    _tick();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
  }

  void _tick() {
    final now = DateTime.now();
    jam.value =
        '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}:${now.second.toString().padLeft(2, '0')}';
  }

  Future<void> load() async {
    loading.value = true;
    try {
      absensi.value = await _repo.hariIni();
    } on AppException catch (e) {
      AppSnackbar.error(e.message);
    } finally {
      loading.value = false;
    }
  }

  Future<void> ambilLokasi() async {
    lokasiLoading.value = true;
    try {
      var izin = await Geolocator.checkPermission();
      if (izin == LocationPermission.denied) {
        izin = await Geolocator.requestPermission();
      }
      if (izin == LocationPermission.denied || izin == LocationPermission.deniedForever) {
        alamat.value = 'Izin lokasi ditolak — absen tetap bisa tanpa lokasi';
        return;
      }
      if (!await Geolocator.isLocationServiceEnabled()) {
        alamat.value = 'GPS tidak aktif — absen tetap bisa tanpa lokasi';
        return;
      }
      final pos = await Geolocator.getCurrentPosition();
      _latitude = pos.latitude;
      _longitude = pos.longitude;
      final places = await placemarkFromCoordinates(pos.latitude, pos.longitude);
      if (places.isNotEmpty) {
        final p = places.first;
        alamat.value = [p.street, p.subLocality, p.locality, p.subAdministrativeArea, p.administrativeArea, p.postalCode]
            .where((x) => x != null && x.isNotEmpty)
            .join(', ');
      } else {
        alamat.value = '${pos.latitude}, ${pos.longitude}';
      }
    } catch (_) {
      alamat.value = 'Lokasi tidak tersedia — absen tetap bisa tanpa lokasi';
    } finally {
      lokasiLoading.value = false;
    }
  }

  Future<void> masuk() => _absen(true);
  Future<void> pulang() => _absen(false);

  Future<void> _absen(bool masuk) async {
    submitting.value = true;
    try {
      final kirimAlamat = _latitude != null ? alamat.value : null;
      absensi.value = masuk
          ? await _repo.masuk(latitude: _latitude, longitude: _longitude, alamat: kirimAlamat)
          : await _repo.pulang(latitude: _latitude, longitude: _longitude, alamat: kirimAlamat);
      AppSnackbar.success(masuk ? 'Absen masuk tercatat' : 'Absen pulang tercatat');
    } on AppException catch (e) {
      AppSnackbar.error(e.message);
    } finally {
      submitting.value = false;
    }
  }
}
```

- [ ] **Step 5: View**

Buat `karyawan_absensi_view.dart` — `StatelessWidget` dengan `Get.put`/pola controller yang dipakai fitur karyawan lain (cek `karyawan_home_view.dart` untuk pola inisialisasi controller & struktur halaman, ikuti). Struktur UI (gaya `TmnColors`, padding `pagePadding()` dari `core/responsive.dart` bila dipakai halaman lain):

```dart
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../../core/tmn_theme.dart';
import 'karyawan_absensi_controller.dart';

class KaryawanAbsensiView extends StatelessWidget {
  const KaryawanAbsensiView({super.key});

  static const _hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

  String get _tanggalHariIni {
    final n = DateTime.now();
    final dd = n.day.toString().padLeft(2, '0');
    final mm = n.month.toString().padLeft(2, '0');
    return '${_hari[n.weekday - 1]}, $dd-$mm-${n.year}';
  }

  @override
  Widget build(BuildContext context) {
    final c = Get.isRegistered<KaryawanAbsensiController>()
        ? Get.find<KaryawanAbsensiController>()
        : Get.put(KaryawanAbsensiController());

    return Scaffold(
      backgroundColor: TmnColors.bg,
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async {
            await c.load();
            await c.ambilLokasi();
          },
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _kartuJam(c),
              const SizedBox(height: 16),
              _kartuLokasi(c),
              const SizedBox(height: 24),
              _tombolAbsen(c),
            ],
          ),
        ),
      ),
    );
  }

  Widget _kartuJam(KaryawanAbsensiController c) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 20),
      decoration: BoxDecoration(gradient: TmnColors.gradHero, borderRadius: BorderRadius.circular(20)),
      child: Column(
        children: [
          Text(_tanggalHariIni,
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 14)),
          const SizedBox(height: 8),
          Obx(() => Text(c.jam.value,
              style: const TextStyle(color: Colors.white, fontSize: 52, fontWeight: FontWeight.w700, letterSpacing: 2))),
          const SizedBox(height: 20),
          Obx(() {
            final a = c.absensi.value;
            return Row(
              children: [
                _kolomWaktu('Masuk', a?.tanggal, a?.jamMasuk),
                Container(width: 1, height: 40, color: Colors.white24),
                _kolomWaktu('Pulang', a?.tanggal, a?.jamPulang),
              ],
            );
          }),
        ],
      ),
    );
  }

  Widget _kolomWaktu(String label, String? tanggal, String? jam) {
    return Expanded(
      child: Column(
        children: [
          Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
          const SizedBox(height: 4),
          Text(jam != null ? jam.substring(0, 5) : '—',
              style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
          if (jam != null && tanggal != null)
            Text(tanggal, style: const TextStyle(color: Colors.white70, fontSize: 11)),
        ],
      ),
    );
  }

  Widget _kartuLokasi(KaryawanAbsensiController c) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TmnColors.surface,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 10, offset: const Offset(0, 4))],
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.location_on_rounded, color: TmnColors.primary, size: 28),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Lokasi Anda', style: TextStyle(fontWeight: FontWeight.w700, color: TmnColors.textPrimary)),
                const SizedBox(height: 4),
                Obx(() => c.lokasiLoading.value
                    ? const Padding(
                        padding: EdgeInsets.symmetric(vertical: 8),
                        child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                      )
                    : Text(c.alamat.value.isEmpty ? 'Mengambil lokasi…' : c.alamat.value,
                        style: const TextStyle(color: TmnColors.textSub, fontSize: 13, height: 1.4))),
              ],
            ),
          ),
          Obx(() => IconButton(
                onPressed: c.lokasiLoading.value ? null : c.ambilLokasi,
                icon: const Icon(Icons.refresh_rounded, color: TmnColors.textSub),
              )),
        ],
      ),
    );
  }

  Widget _tombolAbsen(KaryawanAbsensiController c) {
    return Obx(() {
      final a = c.absensi.value;
      final bisaMasuk = !c.loading.value && !c.submitting.value && (a == null || !a.sudahMasuk);
      final bisaPulang = !c.loading.value && !c.submitting.value && a != null && a.sudahMasuk && !a.sudahPulang;

      return Row(
        children: [
          Expanded(
            child: TmnGradientButton(
              label: 'Masuk',
              loading: c.submitting.value && bisaMasuk,
              onPressed: bisaMasuk ? c.masuk : null,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: OutlinedButton(
              onPressed: bisaPulang ? c.pulang : null,
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
                side: const BorderSide(color: TmnColors.navy),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              child: Text('Pulang',
                  style: TextStyle(color: bisaPulang ? TmnColors.navy : TmnColors.textSub, fontWeight: FontWeight.w600)),
            ),
          ),
        ],
      );
    });
  }
}
```

Sesuaikan detail kecil dengan pola view karyawan existing (mis. cara ambil controller); `TmnGradientButton` menerima `onPressed` null → tombol mati. Bila `withValues` belum tersedia di versi Flutter proyek, pakai `withOpacity`.

- [ ] **Step 6: Tab ketiga di `karyawan_main_view.dart`**

Tambah import view baru; `_pages` jadi `[KaryawanHomeView(), KaryawanAbsensiView(), KaryawanProfilView()]`; items tambah di tengah: `BottomNavigationBarItem(icon: Icon(Icons.fingerprint_rounded), label: 'Absensi')`.

- [ ] **Step 7: Analyze**

Run: `flutter analyze` dari folder mobile.
Expected: No issues found (atau hanya info pre-existing — laporkan bila ada).

- [ ] **Step 8: Laporkan file berubah (tanpa commit)**
