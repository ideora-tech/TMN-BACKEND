# Migrasi Eloquent ke Query Builder — Kelompok 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Konversi 5 modul (Karyawan, KaryawanExit, Supir, Rute, Klien) dari Eloquent Model ke Query Builder murni, plus patch kompatibilitas ke 4 modul luar Kelompok 2 yang punya relasi Eloquent langsung ke Model yang akan dihapus (Auth/Pengguna → Karyawan, LaporanOperasional → Karyawan, JadwalKeberangkatan → Supir, Faktur → Klien).

**Architecture:** Sama persis dengan Kelompok 1 (`docs/superpowers/specs/2026-07-15-eloquent-to-query-builder-design.md`, `docs/superpowers/plans/2026-07-15-eloquent-to-query-builder-phase1.md`) — `App\Support\RecordHelper` untuk stamp UUID/audit/soft-delete, `?object`/`object` menggantikan type-hint Model, `DB::table()` murni tanpa `SELECT *` (eksplisit `private const COLUMNS`). Bedanya: Kelompok 2 punya jauh lebih banyak kopling silang ke modul di luar kelompok — 4 modul luar HARUS dipatch dulu sebelum Model dihapus, satu di antaranya (Auth) ada di **jalur login**.

**Tech Stack:** Laravel 11, PHP 8.2+, MySQL 8 (Docker runtime), SQLite in-memory (PHPUnit via `vendor/bin/phpunit` di host — BUKAN `php artisan test`, BUKAN di dalam container).

## Global Constraints

- **Vendor & 4 modul turunannya (ArmadaVendor, DokumenVendor, KontrakVendor, SupirVendor) TIDAK termasuk kelompok ini** — sengaja dikeluarkan karena kopling paling berat, akan jadi kelompok tersendiri nanti.
- Tidak boleh menjalankan `git commit` di task manapun — user commit manual sendiri. Tinggalkan perubahan di working tree.
- Primary key semua tabel custom (`id_karyawan`, `id_supir`, dst) — **jangan** pakai `DB::table()->find($id)`, selalu `->where('id_xxx', $id)->first()`.
- **Tidak boleh ada `SELECT *`** — setiap query eksplisit sebutkan kolom via `private const COLUMNS` per Repository, dipakai di `.select()`/`.first()`/`.get()`/`.paginate()`. Perkecualian: `KlienRepository::paginateProyek()` dan `SupirService`'s `ArmadaRepositoryInterface` dependency — keduanya menyentuh modul **di luar** Kelompok 2 (Proyek, Armada) yang tetap Eloquent, JANGAN diubah.
- `RecordHelper` dipanggil eksplisit di Repository sebelum `insert()`/`update()`.
- Setiap task tetap harus verified test-first: tulis test dulu, jalankan sebagai baseline terhadap kode LAMA (harus PASS), baru refactor, jalankan lagi (harus tetap PASS).
- **Auth adalah jalur paling kritis** — Task 1 (patch Auth/Pengguna) WAJIB selesai & lolos SEBELUM Task 5 (konversi Karyawan) dieksekusi. Jangan pernah membiarkan aplikasi dalam kondisi `KaryawanModel` terhapus tapi Auth belum dipatch.

---

### Task 1: Patch Auth/Pengguna → Karyawan (KRITIS — jalur login)

**Konteks:** `app/Models/Pengguna.php` (model Sanctum, TIDAK PERNAH dikonversi — lihat desain, Sanctum butuh Eloquent) punya relasi `belongsTo` ke `KaryawanModel`. `AuthRepository::findActiveByUsernameOrEmail()` (dipanggil setiap login) memanggil `Pengguna::with('karyawan')`. Frontend (`auth.config.ts:35`) baca `pengguna.karyawan.nama_karyawan` untuk nama tampilan setelah login, fallback ke `username` kalau kosong. Task 5 akan menghapus `KaryawanModel` — kalau task ini tidak dikerjakan dulu, **login akan 500 error**.

**Files:**
- Modify: `app/Models/Pengguna.php`
- Modify: `app/Modules/Auth/AuthRepository.php`
- Test: `tests/Feature/AuthKaryawanTest.php`

**Interfaces:**
- Produces: `AuthRepository::findActiveByUsernameOrEmail()` tetap return `?Pengguna`, tapi field `karyawan` sekarang diisi manual (bukan Eloquent relation) — bentuk JSON keluaran identik: `{id_karyawan, nama_karyawan, ...semua kolom karyawan}` atau `null`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/AuthKaryawanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthKaryawanTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_menyertakan_nama_karyawan_saat_pengguna_terhubung_ke_karyawan(): void
    {
        $this->ensurePerusahaan();

        $idKaryawan = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $idKaryawan,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Rina Kartika',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);

        Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_karyawan'   => $idKaryawan,
            'kode_peran'    => 'ADMIN',
            'username'      => 'rina_test',
            'email'         => 'rina@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'rina_test',
            'password' => 'Password123!',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.pengguna.karyawan.nama_karyawan', 'Rina Kartika')
            ->assertJsonPath('data.pengguna.karyawan.id_karyawan', $idKaryawan);
    }

    public function test_login_pengguna_tanpa_karyawan_tidak_error(): void
    {
        $this->ensurePerusahaan();

        Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'ADMIN',
            'username'      => 'admin_test',
            'email'         => 'admin@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin_test',
            'password' => 'Password123!',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.pengguna.karyawan', null);
    }

    public function test_login_gagal_dengan_password_salah(): void
    {
        $this->ensurePerusahaan();

        Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'ADMIN',
            'username'      => 'salah_test',
            'email'         => 'salah@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'salah_test',
            'password' => 'PasswordSalah!',
        ]);

        $res->assertStatus(401);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline sebelum diubah)**

Run: `vendor/bin/phpunit tests/Feature/AuthKaryawanTest.php`
Expected: PASS (3 test lolos) — kode existing masih Eloquent relationship, harus sudah jalan sekarang. Ini baseline sebelum refactor.

- [ ] **Step 3: Patch `Pengguna.php` — hapus relasi `karyawan()`**

Ganti isi `app/Models/Pengguna.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\HasSoftDeleteColumns;
use App\Traits\HasUuidPrimaryKey;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Pengguna extends Authenticatable
{
    use HasApiTokens, HasUuidPrimaryKey, HasAuditColumns, HasSoftDeleteColumns;

    protected $table = 'pengguna';
    protected $primaryKey = 'id_pengguna';
    public $timestamps = false;

    protected $hidden = ['kata_sandi'];

    protected $fillable = [
        'id_pengguna', 'id_perusahaan', 'kode_peran', 'id_karyawan',
        'username', 'email', 'kata_sandi', 'aktif',
        'harus_ganti_password', 'login_terakhir',
    ];

    public function getAuthPassword(): string
    {
        return $this->kata_sandi;
    }
}
```

- [ ] **Step 4: Patch `AuthRepository.php` — ganti eager-load jadi lookup manual**

Ganti isi `app/Modules/Auth/AuthRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Models\Pengguna;
use Illuminate\Support\Facades\DB;

class AuthRepository
{
    private const KARYAWAN_COLUMNS = [
        'id_karyawan', 'id_perusahaan', 'id_jabatan', 'id_lokasi', 'nik', 'nama_karyawan',
        'email', 'telepon', 'jenis_kelamin', 'tanggal_lahir', 'tanggal_masuk',
        'status_kepegawaian', 'gaji_pokok', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function findActiveByEmail(string $email): ?Pengguna
    {
        return Pengguna::where('email', $email)
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function findActiveByUsername(string $username): ?Pengguna
    {
        return Pengguna::where('username', $username)
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();
    }

    public function findActiveByUsernameOrEmail(string $identifier): ?Pengguna
    {
        $pengguna = Pengguna::where(function ($q) use ($identifier) {
                $q->where('username', $identifier)->orWhere('email', $identifier);
            })
            ->whereNull('dihapus_pada')
            ->where('aktif', 1)
            ->first();

        if ($pengguna !== null) {
            $karyawan = $pengguna->id_karyawan !== null
                ? DB::table('karyawan')
                    ->select(self::KARYAWAN_COLUMNS)
                    ->where('id_karyawan', $pengguna->id_karyawan)
                    ->first()
                : null;

            // setRelation() (bukan assignment atribut biasa!) — assignment biasa
            // ($pengguna->karyawan = ...) akan dianggap Eloquent sebagai kolom
            // dirty dan ikut ke-UPDATE saat updateLoginTimestamp()->saveQuietly()
            // dipanggil setelah ini, meledak dengan "no such column: karyawan".
            // setRelation() simpan di $relations, terpisah dari $attributes, jadi
            // tidak pernah ikut query UPDATE. collect() dibutuhkan karena
            // relationsToArray() cuma serialize value null atau yang Arrayable —
            // stdClass polos dari DB::table() akan hilang diam-diam kalau tidak
            // dibungkus. Selalu dipanggil (bukan cuma saat ada karyawan) supaya
            // key "karyawan" tetap muncul sebagai null di JSON, persis seperti
            // eager-load Eloquent lama.
            $pengguna->setRelation('karyawan', $karyawan !== null ? collect((array) $karyawan) : null);
        }

        return $pengguna;
    }

    public function updateLoginTimestamp(Pengguna $pengguna): void
    {
        $pengguna->login_terakhir = now();
        $pengguna->saveQuietly();
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan tetap lolos setelah patch**

Run: `vendor/bin/phpunit tests/Feature/AuthKaryawanTest.php`
Expected: PASS (3 test lolos) — perilaku identik, mekanisme berubah dari Eloquent relationship ke raw lookup.

- [ ] **Step 6: Commit**

Jangan commit — biarkan di working tree.

---

### Task 2: Patch LaporanOperasional → Karyawan

**Konteks:** `LaporanOperasionalRepository::karyawanAktif()` memanggil `KaryawanModel::active()->where(...)->orderBy(...)->get()` langsung (bukan lewat `KaryawanRepositoryInterface`), dipakai endpoint `GET /laporan/karyawan/export/excel` dan `/pdf`. Return type `EloquentCollection` dipakai konsisten Interface → Repository → Service → Controller. `armadaAktif()` di file yang sama **JANGAN disentuh** — Armada bukan bagian migrasi ini.

**Files:**
- Modify: `app/Modules/LaporanOperasional/Contracts/LaporanOperasionalRepositoryInterface.php`
- Modify: `app/Modules/LaporanOperasional/LaporanOperasionalRepository.php`
- Modify: `app/Modules/LaporanOperasional/LaporanOperasionalService.php`
- Test: `tests/Feature/LaporanKaryawanExportTest.php`

**Interfaces:**
- Produces: `karyawanAktif(string $idPerusahaan): \Illuminate\Support\Collection` (berubah dari `EloquentCollection`) — dipakai `LaporanOperasionalController::exportKaryawanExcel()`/`exportKaryawanPdf()`, keduanya cuma `collect($items)` ulang jadi kompatibel otomatis.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/LaporanKaryawanExportTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaporanKaryawanExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $nama): void
    {
        DB::table('karyawan')->insert([
            'id_karyawan'        => (string) Str::uuid(),
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => $nama,
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
    }

    public function test_export_karyawan_excel_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan('Karyawan Excel Test');

        $res = $this->get('/api/v1/laporan/karyawan/export/excel');

        $res->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('Content-Type')
        );
    }

    public function test_export_karyawan_pdf_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan('Karyawan PDF Test');

        $res = $this->get('/api/v1/laporan/karyawan/export/pdf');

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }

    public function test_export_karyawan_excel_tanpa_data_tetap_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->get('/api/v1/laporan/karyawan/export/excel');

        $res->assertStatus(200);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `vendor/bin/phpunit tests/Feature/LaporanKaryawanExportTest.php`
Expected: PASS (3 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Di `app/Modules/LaporanOperasional/Contracts/LaporanOperasionalRepositoryInterface.php`, ganti baris:

```php
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;

interface LaporanOperasionalRepositoryInterface
{
    public function queryTrip(string $idPerusahaan, array $filter): Builder;
    public function ringkasanTrip(string $idPerusahaan, array $filter): array;
    public function karyawanAktif(string $idPerusahaan): EloquentCollection;
    public function armadaAktif(string $idPerusahaan): EloquentCollection;
}
```

jadi:

```php
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

interface LaporanOperasionalRepositoryInterface
{
    public function queryTrip(string $idPerusahaan, array $filter): Builder;
    public function ringkasanTrip(string $idPerusahaan, array $filter): array;
    public function karyawanAktif(string $idPerusahaan): Collection;
    public function armadaAktif(string $idPerusahaan): EloquentCollection;
}
```

(Cuma baris `karyawanAktif` yang berubah tipe kembalian; `armadaAktif` tetap `EloquentCollection`, JANGAN diubah.)

- [ ] **Step 4: Ganti method `karyawanAktif()` di Repository**

Di `app/Modules/LaporanOperasional/LaporanOperasionalRepository.php`, ganti:

```php
    public function karyawanAktif(string $idPerusahaan): EloquentCollection
    {
        return KaryawanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_karyawan')
            ->get();
    }
```

jadi:

```php
    private const KARYAWAN_COLUMNS = [
        'id_karyawan', 'nik', 'nama_karyawan', 'email', 'telepon',
        'jenis_kelamin', 'status_kepegawaian', 'tanggal_masuk', 'aktif',
    ];

    public function karyawanAktif(string $idPerusahaan): \Illuminate\Support\Collection
    {
        return DB::table('karyawan')
            ->select(self::KARYAWAN_COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_karyawan')
            ->get();
    }
```

Hapus baris `use App\Modules\Karyawan\KaryawanModel;` dari bagian import file ini (`armadaAktif()` masih pakai `ArmadaModel`, import itu JANGAN dihapus).

Catatan: `KARYAWAN_COLUMNS` di sini sengaja cuma kolom yang benar-benar dipakai `KaryawanExport`/blade view (`nik, nama_karyawan, email, telepon, jenis_kelamin, status_kepegawaian, tanggal_masuk, aktif`) plus `id_karyawan`, bukan semua kolom tabel — beda dari Repository CRUD Karyawan sendiri (Task 5) yang butuh semua kolom karena dipakai untuk create/update/detail penuh. Ini query khusus laporan read-only, scope kolomnya sengaja lebih sempit.

- [ ] **Step 5: Ganti type hint di Service**

Di `app/Modules/LaporanOperasional/LaporanOperasionalService.php`, ganti baris:

```php
    public function karyawanAktif(string $idPerusahaan): EloquentCollection
    {
        return $this->repo->karyawanAktif($idPerusahaan);
    }
```

jadi (ganti tipe kembalian saja, badan method sama):

```php
    public function karyawanAktif(string $idPerusahaan): Collection
    {
        return $this->repo->karyawanAktif($idPerusahaan);
    }
```

Pastikan `use Illuminate\Support\Collection;` sudah ada di bagian import file ini (kalau belum ada, tambahkan; `EloquentCollection` punya alias sendiri dan tetap dipakai `armadaAktif()`, jangan dihapus importnya).

- [ ] **Step 6: Jalankan test, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/LaporanKaryawanExportTest.php`
Expected: PASS (3 test lolos)

- [ ] **Step 7: Commit**

Jangan commit — biarkan di working tree.

---

### Task 3: Patch JadwalKeberangkatan → Supir

**Konteks:** `JadwalKeberangkatanController::saya()` memanggil `SupirModel::active()->where('id_pengguna', ...)->first()` LANGSUNG di Controller (bypass Repository & Service sama sekali — pelanggaran pola layering yang sudah ada sejak awal, bukan cuma soal Eloquent). `SupirRepositoryInterface::findByPengguna()` **sudah ada** dan punya logika identik — task ini sekalian membetulkan anti-pattern itu, bukan cuma migrasi Eloquent→Query Builder.

**Files:**
- Modify: `app/Modules/JadwalKeberangkatan/JadwalKeberangkatanController.php`
- Test: `tests/Feature/JadwalSayaTest.php`

**Interfaces:**
- Consumes: `App\Modules\Supir\Contracts\SupirRepositoryInterface::findByPengguna(string $idPengguna): ?SupirModel` (masih `?SupirModel` sampai Task 7 mengubahnya jadi `?object` — Controller cuma baca `$supir->id_supir`, kompatibel dengan kedua tipe).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/JadwalSayaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JadwalSayaTest extends TestCase
{
    use RefreshDatabase;

    public function test_jadwal_saya_mengembalikan_jadwal_milik_supir_yang_login(): void
    {
        $this->ensurePerusahaan();

        $idPengguna = (string) Str::uuid();
        $pengguna = Pengguna::create([
            'id_pengguna'   => $idPengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'supir_test',
            'email'         => 'supir@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        DB::table('supir')->insert([
            'id_supir'      => (string) Str::uuid(),
            'id_pengguna'   => $idPengguna,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => 'Supir Login Test',
            'no_sim'        => 'SIM-' . Str::random(6),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);

        Sanctum::actingAs($pengguna, ['*']);

        $res = $this->getJson('/api/v1/jadwal/saya');

        $res->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_jadwal_saya_tanpa_data_supir_mengembalikan_404(): void
    {
        $this->ensurePerusahaan();

        $pengguna = Pengguna::create([
            'id_pengguna'   => (string) Str::uuid(),
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_peran'    => 'SUPIR',
            'username'      => 'bukan_supir',
            'email'         => 'bukansupir@test.id',
            'kata_sandi'    => bcrypt('Password123!'),
            'aktif'         => 1,
        ]);

        Sanctum::actingAs($pengguna, ['*']);

        $res = $this->getJson('/api/v1/jadwal/saya');

        $res->assertStatus(404);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `vendor/bin/phpunit tests/Feature/JadwalSayaTest.php`
Expected: PASS (2 test lolos) — kode existing masih query `SupirModel` langsung, harus sudah jalan.

- [ ] **Step 3: Patch Controller — pakai `SupirRepositoryInterface` bukan Model langsung**

Ganti isi `app/Modules/JadwalKeberangkatan/JadwalKeberangkatanController.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalKeberangkatan;

use App\Helpers\ApiResponse;
use App\Modules\JadwalKeberangkatan\Requests\StoreJadwalKeberangkatanRequest;
use App\Modules\JadwalKeberangkatan\Requests\UpdateJadwalKeberangkatanRequest;
use App\Modules\JadwalKeberangkatan\Resources\JadwalKeberangkatanResource;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JadwalKeberangkatanController extends Controller
{
    public function __construct(
        private readonly JadwalKeberangkatanService $service,
        private readonly SupirRepositoryInterface $supirRepo,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page  = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 10);

        if ($request->filled('id_penugasan')) {
            $result = $this->service->list((string) $request->get('id_penugasan'), $page, $limit);
        } else {
            $idPerusahaan = $request->user()->id_perusahaan;
            if (!$idPerusahaan) {
                return ApiResponse::paginated(collect([]), ['page'=>1,'limit'=>$limit,'total'=>0,'totalPages'=>0]);
            }
            $result = $this->service->listByPerusahaan($idPerusahaan, $page, $limit);
        }

        return ApiResponse::paginated(
            JadwalKeberangkatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success(new JadwalKeberangkatanResource($this->service->findOrFail($id)));
    }

    public function saya(Request $request): JsonResponse
    {
        $supir = $this->supirRepo->findByPengguna((string) $request->user()->id_pengguna);

        if (!$supir) {
            abort(404, 'Data supir tidak ditemukan untuk pengguna ini');
        }

        $result = $this->service->listBySupir(
            (string) $supir->id_supir,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            JadwalKeberangkatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function bySupir(Request $request, string $idSupir): JsonResponse
    {
        $result = $this->service->listBySupir(
            $idSupir,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 20)
        );

        return ApiResponse::paginated(
            JadwalKeberangkatanResource::collection($result['data']),
            $result['meta']
        );
    }

    public function store(StoreJadwalKeberangkatanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated());
        return ApiResponse::success(
            new JadwalKeberangkatanResource($record),
            'Jadwal keberangkatan berhasil dibuat',
            201
        );
    }

    public function update(UpdateJadwalKeberangkatanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated());
        return ApiResponse::success(
            new JadwalKeberangkatanResource($record),
            'Jadwal keberangkatan berhasil diperbarui'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jadwal keberangkatan berhasil dihapus');
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/JadwalSayaTest.php`
Expected: PASS (2 test lolos)

- [ ] **Step 5: Commit**

Jangan commit — biarkan di working tree.

---

### Task 4: Patch Faktur → Klien

**Konteks:** `FakturModel::klien()` relasi `belongsTo` ke `KlienModel`. Dipakai di 3 tempat: `FakturController::exportExcel()` (`->with('klien')`), `::exportPdf()` (`->with('klien')`), dan dibaca di `FakturExport::map()` (`$row->klien->nama_klien`) serta view `resources/views/exports/faktur.blade.php:35` (`$item->klien->nama_klien`). Faktur module SENDIRI **tidak dikonversi** di sini (bukan bagian Kelompok 2) — cuma bagian yang menyentuh `KlienModel` yang dipatch.

**Files:**
- Modify: `app/Modules/Faktur/FakturModel.php`
- Modify: `app/Modules/Faktur/FakturController.php`
- Modify: `app/Modules/Faktur/Exports/FakturExport.php`
- Modify: `resources/views/exports/faktur.blade.php`
- Test: `tests/Feature/FakturKlienExportTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/FakturKlienExportTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Faktur\FakturModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakturKlienExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $nama): string
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(6),
            'nama_klien'    => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return $id;
    }

    private function makeFaktur(string $idKlien): void
    {
        FakturModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_klien'      => $idKlien,
            'nomor_faktur'  => 'INV-' . Str::random(6),
            'total'         => 1500000,
            'status'        => 'draft',
            'tanggal_faktur' => now()->toDateString(),
        ]);
    }

    public function test_export_faktur_excel_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $idKlien = $this->makeKlien('PT Klien Excel');
        $this->makeFaktur($idKlien);

        $res = $this->get('/api/v1/faktur/export/excel');

        $res->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
    }

    public function test_export_faktur_pdf_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $idKlien = $this->makeKlien('PT Klien PDF');
        $this->makeFaktur($idKlien);

        $res = $this->get('/api/v1/faktur/export/pdf');

        $res->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Route sudah dikonfirmasi (`app/Modules/Faktur/FakturServiceProvider.php:26-27`: `GET faktur/export/excel`, `GET faktur/export/pdf`) — path di test Step 1 sudah tepat, tidak perlu penyesuaian.

Run: `vendor/bin/phpunit tests/Feature/FakturKlienExportTest.php`
Expected: PASS (2 test lolos)

- [ ] **Step 3: Hapus relasi `klien()` dari `FakturModel.php`**

Ganti isi `app/Modules/Faktur/FakturModel.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FakturModel extends BaseModel
{
    protected $table = 'faktur';
    protected $primaryKey = 'id_faktur';

    protected $fillable = [
        'id_faktur',
        'id_perusahaan',
        'id_proyek',
        'id_klien',
        'nomor_faktur',
        'total',
        'status',
        'tanggal_faktur',
        'jatuh_tempo',
    ];

    protected $casts = [
        'total'          => 'float',
        'tanggal_faktur' => 'date',
        'jatuh_tempo'    => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FakturItemModel::class, 'id_faktur', 'id_faktur')
            ->whereNull('dihapus_pada');
    }
}
```

- [ ] **Step 4: Patch `FakturController.php` — ganti `with('klien')` jadi lookup manual**

Tambahkan `use Illuminate\Support\Facades\DB;` ke bagian import (setelah `use App\Modules\Faktur\FakturModel;`).

Ganti method `exportExcel()` dan `exportPdf()` (hapus `->with('klien')`, tambah pemanggilan `attachNamaKlien()`), dan tambahkan method private baru `attachNamaKlien()`:

```php
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $items = FakturModel::whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('dibuat_pada', 'DESC')
            ->get();

        $this->attachNamaKlien($items);

        return Excel::download(
            new FakturExport(collect($items)),
            'faktur-' . date('Ymd') . '.xlsx'
        );
    }

    public function exportPdf(Request $request): Response
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $items = FakturModel::whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('dibuat_pada', 'DESC')
            ->get();

        $this->attachNamaKlien($items);

        $pdf = Pdf::loadView('exports.faktur', ['items' => $items]);

        return $pdf->download('faktur-' . date('Ymd') . '.pdf');
    }

    /**
     * Tempel nama_klien via raw query builder (join manual), bukan Eloquent
     * relationship — KlienModel sudah dikonversi ke Query Builder (Task 9)
     * dan tidak lagi punya class Eloquent.
     */
    private function attachNamaKlien(\Illuminate\Support\Collection $items): void
    {
        $idKlienList = $items->pluck('id_klien')->filter()->unique()->values()->all();
        if (empty($idKlienList)) {
            return;
        }

        $namaByIdKlien = DB::table('klien')->whereIn('id_klien', $idKlienList)->pluck('nama_klien', 'id_klien');

        foreach ($items as $item) {
            $item->klien_nama = $namaByIdKlien[$item->id_klien] ?? null;
        }
    }
```

(Method lain di file ini — `index`, `show`, `store`, `update`, `destroy`, `updateStatus` kalau ada — TIDAK disentuh sama sekali.)

- [ ] **Step 5: Patch `FakturExport.php` — baca `klien_nama`, bukan relasi**

Di `app/Modules/Faktur/Exports/FakturExport.php`, ganti baris:

```php
            $row->klien->nama_klien ?? '-',
```

jadi:

```php
            $row->klien_nama ?? '-',
```

- [ ] **Step 6: Patch view Blade PDF**

Di `resources/views/exports/faktur.blade.php` baris 35, ganti:

```php
                <td>{{ $item->klien->nama_klien ?? '-' }}</td>
```

jadi:

```php
                <td>{{ $item->klien_nama ?? '-' }}</td>
```

- [ ] **Step 7: Jalankan test, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/FakturKlienExportTest.php`
Expected: PASS (2 test lolos)

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 5: Konversi modul Karyawan

**Prasyarat:** Task 1 (patch Auth) dan Task 2 (patch LaporanOperasional) HARUS sudah selesai & lolos sebelum task ini mulai — keduanya bergantung ke `KaryawanModel` yang akan dihapus di sini.

**Konteks tambahan:** `KaryawanExitService::create()` (modul lain, `app/Modules/KaryawanExit/`) memanggil `KaryawanModel::find($data['id_karyawan'])` LANGSUNG lalu `->update(['aktif' => 0])` — bypass Repository sepenuhnya. Task ini sekalian membetulkannya dengan inject `KaryawanRepositoryInterface` ke `KaryawanExitService`, karena `KaryawanModel` akan hilang. `KaryawanExitModel` sendiri belum dikonversi di sini (itu Task 6) — cuma cara `KaryawanExitService` bicara ke Karyawan yang berubah.

**Files:**
- Delete: `app/Modules/Karyawan/KaryawanModel.php`
- Modify: `app/Modules/Karyawan/Contracts/KaryawanRepositoryInterface.php`
- Modify: `app/Modules/Karyawan/KaryawanRepository.php`
- Modify: `app/Modules/Karyawan/KaryawanService.php`
- Modify: `app/Modules/KaryawanExit/KaryawanExitService.php`
- Test: `tests/Feature/KaryawanTest.php`

**Interfaces:**
- Produces: `KaryawanRepositoryInterface` dengan `?object`/`object` — dipakai `KaryawanExitService` (via interface, bukan Model langsung) dan `KaryawanController` (tidak berubah).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/KaryawanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(string $idPerusahaan, string $nik, string $nama = 'Karyawan Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => $idPerusahaan,
            'nik'                => $nik,
            'nama_karyawan'      => $nama,
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return DB::table('karyawan')->where('id_karyawan', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_karyawan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/karyawan', [
            'nik'                => 'NIK-001',
            'nama_karyawan'      => 'Andi Wijaya',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 5000000,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_karyawan', 'Andi Wijaya')
            ->assertJsonPath('data.gaji_pokok', 5000000)
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('karyawan', ['nik' => 'NIK-001', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_menolak_nik_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-DUP');

        $res = $this->postJson('/api/v1/karyawan', [
            'nik'                => 'NIK-DUP',
            'nama_karyawan'      => 'Duplikat',
            'status_kepegawaian' => 'tetap',
        ]);

        $res->assertStatus(422);
    }

    public function test_list_karyawan_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-A', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeKaryawan($idLain, 'NIK-B', 'Milik Lain');

        $res = $this->getJson('/api/v1/karyawan');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_karyawan']);
    }

    public function test_show_karyawan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-SHOW');

        $res = $this->getJson("/api/v1/karyawan/{$item->id_karyawan}");

        $res->assertStatus(200)->assertJsonPath('data.id_karyawan', $item->id_karyawan);
    }

    public function test_show_karyawan_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/karyawan/' . Str::uuid()->toString());

        $res->assertStatus(404);
    }

    public function test_update_karyawan_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-UPD');

        $res = $this->putJson("/api/v1/karyawan/{$item->id_karyawan}", [
            'nama_karyawan' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_karyawan', 'Nama Diperbarui');
        $this->assertDatabaseHas('karyawan', ['id_karyawan' => $item->id_karyawan, 'nama_karyawan' => 'Nama Diperbarui']);
    }

    public function test_hapus_karyawan_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKaryawan(self::PERUSAHAAN_ID, 'NIK-DEL');

        $res = $this->deleteJson("/api/v1/karyawan/{$item->id_karyawan}");
        $res->assertStatus(200);

        $row = DB::table('karyawan')->where('id_karyawan', $item->id_karyawan)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
```

- [ ] **Step 2: Jalankan test baru DAN dua test regresi (baseline sebelum diubah)**

Run: `vendor/bin/phpunit tests/Feature/KaryawanTest.php tests/Feature/KaryawanJabatanLokasiTest.php tests/Feature/AuthKaryawanTest.php`
Expected: PASS semua (8 + 3 + 3 = 14 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/Karyawan/Contracts/KaryawanRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Karyawan\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KaryawanRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByNik(string $nik): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function exitHistory(string $idKaryawan): array;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/Karyawan/KaryawanRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KaryawanRepository implements KaryawanRepositoryInterface
{
    private const COLUMNS = [
        'id_karyawan', 'id_perusahaan', 'id_jabatan', 'id_lokasi', 'nik', 'nama_karyawan',
        'email', 'telepon', 'jenis_kelamin', 'tanggal_lahir', 'tanggal_masuk',
        'status_kepegawaian', 'gaji_pokok', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        $paginator = DB::table('karyawan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_karyawan')
            ->paginate($limit, self::COLUMNS, 'page', $page);

        $this->attachJabatanLokasi($paginator->getCollection());

        return $paginator;
    }

    public function findById(string $id): ?object
    {
        $record = DB::table('karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_karyawan', $id)
            ->first();

        if ($record !== null) {
            $this->attachJabatanLokasi(collect([$record]));
        }

        return $record;
    }

    public function findByNik(string $nik): ?object
    {
        return DB::table('karyawan')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('nik', $nik)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_karyawan');
        DB::table('karyawan')->insert($data);
        return $this->findById($data['id_karyawan']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('karyawan')
            ->where('id_karyawan', $record->id_karyawan)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_karyawan);
    }

    public function delete(object $record): void
    {
        DB::table('karyawan')
            ->where('id_karyawan', $record->id_karyawan)
            ->update(RecordHelper::stampDelete());
    }

    public function exitHistory(string $idKaryawan): array
    {
        return DB::table('karyawan_exit')
            ->where('id_karyawan', $idKaryawan)
            ->orderBy('tanggal_efektif', 'desc')
            ->get()
            ->all();
    }

    /**
     * Tempel nama jabatan & lokasi via raw query builder (join manual),
     * bukan Eloquent relationship.
     */
    private function attachJabatanLokasi(Collection $records): void
    {
        $idJabatanList = $records->pluck('id_jabatan')->filter()->unique()->values()->all();
        $idLokasiList  = $records->pluck('id_lokasi')->filter()->unique()->values()->all();

        $namaJabatanById = empty($idJabatanList)
            ? collect()
            : DB::table('jabatan')->whereIn('id_jabatan', $idJabatanList)->pluck('nama_jabatan', 'id_jabatan');

        $namaLokasiById = empty($idLokasiList)
            ? collect()
            : DB::table('lokasi_kantor')->whereIn('id_lokasi', $idLokasiList)->pluck('nama_lokasi', 'id_lokasi');

        foreach ($records as $record) {
            $record->jabatan_nama = $namaJabatanById[$record->id_jabatan] ?? null;
            $record->lokasi_nama  = $namaLokasiById[$record->id_lokasi] ?? null;
        }
    }
}
```

Catatan: `exitHistory()` SENGAJA tidak difilter `whereNull('dihapus_pada')` — kode Eloquent lama (`KaryawanExitModel::where('id_karyawan', $idKaryawan)->get()`) juga tidak menyaring itu. Perilaku dipertahankan identik.

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/Karyawan/KaryawanService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Karyawan;

use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;

class KaryawanService
{
    public function __construct(private readonly KaryawanRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Karyawan tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $existing = $this->repo->findByNik($data['nik']);
        if ($existing !== null) {
            abort(422, 'NIK sudah terdaftar');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function exitHistory(string $id): array
    {
        $this->findOrFail($id);
        return $this->repo->exitHistory($id);
    }
}
```

- [ ] **Step 6: Patch `KaryawanExitService.php` — pakai `KaryawanRepositoryInterface`, bukan Model langsung**

Ganti isi `app/Modules/KaryawanExit/KaryawanExitService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;

class KaryawanExitService
{
    public function __construct(
        private readonly KaryawanExitRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    public function create(array $data): KaryawanExitModel
    {
        $exit = $this->repo->create($data);

        $karyawan = $this->karyawanRepo->findById($data['id_karyawan']);
        if ($karyawan !== null) {
            $this->karyawanRepo->update($karyawan, ['aktif' => 0]);
        }

        return $exit;
    }
}
```

(Return type `KaryawanExitModel` SENGAJA belum diubah — `KaryawanExitRepositoryInterface::create()` masih Eloquent sampai Task 6. Cuma cara bicara ke Karyawan yang berubah di task ini.)

- [ ] **Step 7: Hapus Model**

Run: `rm "app/Modules/Karyawan/KaryawanModel.php"`

- [ ] **Step 8: Jalankan SEMUA test terkait, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/KaryawanTest.php tests/Feature/KaryawanJabatanLokasiTest.php tests/Feature/AuthKaryawanTest.php tests/Feature/LaporanKaryawanExportTest.php`
Expected: PASS semua (8 + 3 + 3 + 3 = 17 test lolos) — ini konfirmasi Task 1 & 2 (patch Auth & LaporanOperasional) benar-benar menyelamatkan kedua modul itu setelah `KaryawanModel` dihapus.

- [ ] **Step 9: Commit**

Jangan commit — biarkan di working tree.

---

### Task 6: Konversi modul KaryawanExit

**Files:**
- Delete: `app/Modules/KaryawanExit/KaryawanExitModel.php`
- Modify: `app/Modules/KaryawanExit/Contracts/KaryawanExitRepositoryInterface.php`
- Modify: `app/Modules/KaryawanExit/KaryawanExitRepository.php`
- Modify: `app/Modules/KaryawanExit/KaryawanExitService.php`
- Test: `tests/Feature/KaryawanExitTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/KaryawanExitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KaryawanExitTest extends TestCase
{
    use RefreshDatabase;

    private function makeKaryawan(): string
    {
        $id = (string) Str::uuid();
        DB::table('karyawan')->insert([
            'id_karyawan'        => $id,
            'id_perusahaan'      => self::PERUSAHAAN_ID,
            'nik'                => 'NIK-' . Str::random(6),
            'nama_karyawan'      => 'Karyawan Exit Test',
            'status_kepegawaian' => 'tetap',
            'gaji_pokok'         => 4000000,
            'aktif'              => 1,
            'dibuat_pada'        => now(),
        ]);
        return $id;
    }

    public function test_membuat_karyawan_exit_berhasil_dan_menonaktifkan_karyawan(): void
    {
        $this->actingAsRole('ADMIN');
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/v1/karyawan-exit', [
            'id_karyawan'     => $idKaryawan,
            'jenis_exit'      => 'resign',
            'tanggal_efektif' => '2026-08-01',
            'alasan'          => 'Pindah kerja',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.jenis_exit', 'resign');

        $this->assertDatabaseHas('karyawan_exit', [
            'id_karyawan' => $idKaryawan,
            'jenis_exit'  => 'resign',
        ]);

        $karyawan = DB::table('karyawan')->where('id_karyawan', $idKaryawan)->first();
        $this->assertSame(0, (int) $karyawan->aktif);
    }

    public function test_menolak_karyawan_exit_tanpa_jenis_exit(): void
    {
        $this->actingAsRole('ADMIN');
        $idKaryawan = $this->makeKaryawan();

        $res = $this->postJson('/api/v1/karyawan-exit', [
            'id_karyawan'     => $idKaryawan,
            'tanggal_efektif' => '2026-08-01',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['jenis_exit']);
    }

    public function test_karyawan_exit_muncul_di_exit_history_karyawan(): void
    {
        $this->actingAsRole('ADMIN');
        $idKaryawan = $this->makeKaryawan();

        $this->postJson('/api/v1/karyawan-exit', [
            'id_karyawan'     => $idKaryawan,
            'jenis_exit'      => 'pensiun',
            'tanggal_efektif' => '2026-09-01',
        ]);

        $res = $this->getJson("/api/v1/karyawan/{$idKaryawan}/exit-history");

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('pensiun', $data[0]['jenis_exit']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan lolos DULU (baseline)**

Run: `vendor/bin/phpunit tests/Feature/KaryawanExitTest.php`
Expected: PASS (3 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/KaryawanExit/Contracts/KaryawanExitRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit\Contracts;

interface KaryawanExitRepositoryInterface
{
    public function create(array $data): object;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/KaryawanExit/KaryawanExitRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class KaryawanExitRepository implements KaryawanExitRepositoryInterface
{
    private const COLUMNS = [
        'id_exit', 'id_perusahaan', 'id_karyawan', 'jenis_exit', 'tanggal_efektif',
        'alasan', 'dapat_direkrut_kembali',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_exit');
        DB::table('karyawan_exit')->insert($data);
        return DB::table('karyawan_exit')->select(self::COLUMNS)->where('id_exit', $data['id_exit'])->first();
    }
}
```

- [ ] **Step 5: Ganti Service (type hint jadi `object`)**

Ganti isi `app/Modules/KaryawanExit/KaryawanExitService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\KaryawanExit;

use App\Modules\KaryawanExit\Contracts\KaryawanExitRepositoryInterface;
use App\Modules\Karyawan\Contracts\KaryawanRepositoryInterface;

class KaryawanExitService
{
    public function __construct(
        private readonly KaryawanExitRepositoryInterface $repo,
        private readonly KaryawanRepositoryInterface $karyawanRepo,
    ) {}

    public function create(array $data): object
    {
        $exit = $this->repo->create($data);

        $karyawan = $this->karyawanRepo->findById($data['id_karyawan']);
        if ($karyawan !== null) {
            $this->karyawanRepo->update($karyawan, ['aktif' => 0]);
        }

        return $exit;
    }
}
```

- [ ] **Step 6: Hapus Model**

Run: `rm "app/Modules/KaryawanExit/KaryawanExitModel.php"`

- [ ] **Step 7: Jalankan test, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/KaryawanExitTest.php`
Expected: PASS (3 test lolos)

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 7: Konversi modul Supir

**Prasyarat:** Task 3 (patch JadwalKeberangkatan) HARUS sudah selesai & lolos sebelum task ini mulai.

**Konteks tambahan:** `tests/Feature/SupirArmadaDefaultTest.php` (SUDAH ADA, dari batch sebelumnya) punya helper `makeSupir()` yang pakai `SupirModel::create(...)`. Task ini WAJIB mengubah helper itu jadi `DB::table('supir')->insert(...)`, kalau tidak test itu akan rusak begitu `SupirModel` dihapus.

**Files:**
- Delete: `app/Modules/Supir/SupirModel.php`
- Modify: `app/Modules/Supir/Contracts/SupirRepositoryInterface.php`
- Modify: `app/Modules/Supir/SupirRepository.php`
- Modify: `app/Modules/Supir/SupirService.php`
- Modify: `tests/Feature/SupirArmadaDefaultTest.php`
- Test: `tests/Feature/SupirTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/SupirTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupirTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupir(string $idPerusahaan, string $nama = 'Supir Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'jenis_sim'     => 'B1',
            'status'        => 'aktif',
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_supir_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/supir', [
            'nama'   => 'Budi Santoso',
            'no_sim' => 'SIM-12345',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama', 'Budi Santoso')
            ->assertJsonPath('data.status', 'aktif');

        $this->assertDatabaseHas('supir', ['nama' => 'Budi Santoso', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_list_supir_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeSupir(self::PERUSAHAAN_ID, 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeSupir($idLain, 'Milik Lain');

        $res = $this->getJson('/api/v1/supir');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama']);
    }

    public function test_show_supir_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/supir/{$item->id_supir}");

        $res->assertStatus(200)->assertJsonPath('data.id_supir', $item->id_supir);
    }

    public function test_update_supir_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/supir/{$item->id_supir}", [
            'nama' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama', 'Nama Diperbarui');
    }

    public function test_hapus_supir_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeSupir(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/supir/{$item->id_supir}");
        $res->assertStatus(200);

        $row = DB::table('supir')->where('id_supir', $item->id_supir)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
```

- [ ] **Step 2: Jalankan test baru DAN test regresi existing (baseline sebelum diubah)**

Run: `vendor/bin/phpunit tests/Feature/SupirTest.php tests/Feature/SupirArmadaDefaultTest.php tests/Feature/JadwalSayaTest.php`
Expected: PASS semua (6 + 5 + 2 = 13 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/Supir/Contracts/SupirRepositoryInterface.php` seluruhnya jadi:

```php
<?php
declare(strict_types=1);
namespace App\Modules\Supir\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface SupirRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByPengguna(string $idPengguna): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/Supir/SupirRepository.php` seluruhnya jadi:

```php
<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupirRepository implements SupirRepositoryInterface
{
    private const COLUMNS = [
        'id_supir', 'id_pengguna', 'id_perusahaan', 'id_armada_default', 'nama', 'no_sim',
        'jenis_sim', 'tgl_kadaluarsa_sim', 'telepon', 'status', 'foto',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('supir')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama', 'asc')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_supir', $id)
            ->first();
    }

    public function findByPengguna(string $idPengguna): ?object
    {
        return DB::table('supir')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_pengguna', $idPengguna)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_supir');
        DB::table('supir')->insert($data);
        return $this->findById($data['id_supir']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('supir')
            ->where('id_supir', $record->id_supir)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_supir);
    }

    public function delete(object $record): void
    {
        DB::table('supir')
            ->where('id_supir', $record->id_supir)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Ganti Service (type hint jadi `object`, logika `assertArmadaDefaultRules` identik)**

Ganti isi `app/Modules/Supir/SupirService.php` seluruhnya jadi:

```php
<?php
declare(strict_types=1);
namespace App\Modules\Supir;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;

class SupirService
{
    public function __construct(
        private readonly SupirRepositoryInterface $repo,
        private readonly ArmadaRepositoryInterface $armadaRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);
        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) { abort(404, 'Supir tidak ditemukan'); }
        return $record;
    }

    public function findByPenggunaOrFail(string $idPengguna): object
    {
        $record = $this->repo->findByPengguna($idPengguna);
        if ($record === null) { abort(404, 'Supir tidak ditemukan'); }
        return $record;
    }

    public function create(array $data): object
    {
        $this->assertArmadaDefaultRules($data, (string) $data['id_perusahaan']);
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);
        $this->assertArmadaDefaultRules($data, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    /**
     * Bila id_armada_default dikirim & tidak null, pastikan armada ada
     * dan milik perusahaan yang sama dengan user (pola guard existing:
     * ArmadaRepository::findById tidak scoped perusahaan, jadi
     * dibandingkan manual di sini).
     */
    private function assertArmadaDefaultRules(array $data, string $idPerusahaan): void
    {
        if (!array_key_exists('id_armada_default', $data) || $data['id_armada_default'] === null) {
            return;
        }

        $armada = $this->armadaRepo->findById((string) $data['id_armada_default']);
        if ($armada === null || (string) $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
```

- [ ] **Step 6: Patch helper `makeSupir()` di test existing `SupirArmadaDefaultTest.php`**

Di `tests/Feature/SupirArmadaDefaultTest.php`, ganti import:

```php
use App\Modules\Supir\SupirModel;
```

jadi:

```php
use Illuminate\Support\Facades\DB;
```

(cek dulu apakah `DB` sudah di-import di file itu — kalau sudah ada, jangan duplikat import).

Ganti method `makeSupir()`:

```php
    private function makeSupir(string $nama = 'Budi Santoso'): SupirModel
    {
        return SupirModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
        ]);
    }
```

jadi:

```php
    private function makeSupir(string $nama = 'Budi Santoso'): object
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama'          => $nama,
            'no_sim'        => 'SIM-' . Str::random(8),
            'dibuat_pada'   => now(),
        ]);
        return DB::table('supir')->where('id_supir', $id)->first();
    }
```

(`makeArmada()` di file yang sama TIDAK disentuh — Armada tetap Eloquent.)

- [ ] **Step 7: Hapus Model**

Run: `rm "app/Modules/Supir/SupirModel.php"`

- [ ] **Step 8: Jalankan SEMUA test terkait, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/SupirTest.php tests/Feature/SupirArmadaDefaultTest.php tests/Feature/JadwalSayaTest.php`
Expected: PASS semua (6 + 5 + 2 = 13 test lolos)

- [ ] **Step 9: Commit**

Jangan commit — biarkan di working tree.

---

### Task 8: Konversi modul Rute

**Konteks:** `RuteService::create()` sudah pre-generate `id_rute` sendiri via `Str::uuid()->toString()` SEBELUM memanggil Repository — ini TIDAK bentrok dengan `RecordHelper::stampCreate()` karena `??=` di dalamnya cuma mengisi kalau kosong. JANGAN diubah/dirapikan, biarkan seperti aslinya (di luar scope task ini). `RuteLokasiTest.php` (SUDAH ADA) pakai `LokasiModel::create()` di helper-nya — `Lokasi` BUKAN bagian migrasi ini (sudah dikonversi terpisah di batch lain, TAPI ternyata `LokasiModel.php` masih ada / belum full Query Builder — TIDAK relevan di sini, jangan disentuh), jadi test itu tidak perlu diubah sama sekali.

**Files:**
- Delete: `app/Modules/Rute/RuteModel.php`
- Modify: `app/Modules/Rute/Contracts/RuteRepositoryInterface.php`
- Modify: `app/Modules/Rute/RuteRepository.php`
- Modify: `app/Modules/Rute/RuteService.php`
- Test: `tests/Feature/RuteTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/RuteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RuteTest extends TestCase
{
    use RefreshDatabase;

    private function makeRute(string $idPerusahaan, string $kode = 'RUT-01', string $nama = 'Rute Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('rute')->insert([
            'id_rute'       => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_rute'     => $kode,
            'nama_rute'     => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('rute')->where('id_rute', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_rute_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute' => 'RUT-BARU',
            'nama_rute' => 'Jakarta - Bandung',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_rute', 'Jakarta - Bandung')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('rute', ['kode_rute' => 'RUT-BARU', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_menolak_kode_rute_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-DUP');

        $res = $this->postJson('/api/v1/rute', [
            'kode_rute' => 'RUT-DUP',
            'nama_rute' => 'Duplikat',
        ]);

        $res->assertStatus(409);
    }

    public function test_list_rute_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-01', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeRute($idLain, 'RUT-01', 'Milik Lain');

        $res = $this->getJson('/api/v1/rute');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_rute']);
    }

    public function test_search_rute_by_nama(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-S1', 'Jakarta Surabaya');
        $this->makeRute(self::PERUSAHAAN_ID, 'RUT-S2', 'Bandung Semarang');

        $res = $this->getJson('/api/v1/rute?search=Jakarta');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Jakarta Surabaya', $data[0]['nama_rute']);
    }

    public function test_update_rute_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeRute(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/rute/{$item->id_rute}", [
            'nama_rute' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_rute', 'Nama Diperbarui');
    }

    public function test_hapus_rute_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeRute(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/rute/{$item->id_rute}");
        $res->assertStatus(200);

        $row = DB::table('rute')->where('id_rute', $item->id_rute)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
```

- [ ] **Step 2: Jalankan test baru DAN test regresi existing (baseline sebelum diubah)**

Run: `vendor/bin/phpunit tests/Feature/RuteTest.php tests/Feature/RuteLokasiTest.php`
Expected: PASS semua (7 + 7 = 14 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/Rute/Contracts/RuteRepositoryInterface.php` seluruhnya jadi:

```php
<?php
namespace App\Modules\Rute\Contracts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RuteRepositoryInterface {
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
}
```

- [ ] **Step 4: Ganti Repository**

Ganti isi `app/Modules/Rute/RuteRepository.php` seluruhnya jadi:

```php
<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RuteRepository implements RuteRepositoryInterface {
    private const COLUMNS = [
        'id_rute', 'id_perusahaan', 'kode_rute', 'nama_rute', 'asal', 'tujuan',
        'id_lokasi_asal', 'id_lokasi_tujuan', 'estimasi_jarak_km', 'estimasi_durasi_menit',
        'keterangan', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search): LengthAwarePaginator {
        return DB::table('rute')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->when($search, fn($q) => $q->where(function($q2) use ($search) {
                $q2->where('nama_rute','like',"%{$search}%")
                   ->orWhere('kode_rute','like',"%{$search}%")
                   ->orWhere('asal','like',"%{$search}%")
                   ->orWhere('tujuan','like',"%{$search}%");
            }))
            ->orderBy('nama_rute')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object {
        return DB::table('rute')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_rute', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object {
        return DB::table('rute')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_rute', $kode)
            ->when($excludeId, fn($q) => $q->where('id_rute','!=',$excludeId))
            ->first();
    }

    public function create(array $data): object {
        $data = RecordHelper::stampCreate($data, 'id_rute');
        DB::table('rute')->insert($data);
        return $this->findById($data['id_rute']);
    }

    public function update(object $record, array $data): object {
        DB::table('rute')
            ->where('id_rute', $record->id_rute)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_rute);
    }

    public function delete(object $record): void {
        DB::table('rute')
            ->where('id_rute', $record->id_rute)
            ->update(RecordHelper::stampDelete());
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint, logika `resolveLokasi`/pre-generate UUID identik)**

Ganti isi `app/Modules/Rute/RuteService.php` seluruhnya jadi:

```php
<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use Illuminate\Support\Str;

class RuteService {
    public function __construct(
        private readonly RuteRepositoryInterface $repo,
        private readonly LokasiRepositoryInterface $lokasiRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array {
        $paginator = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object {
        $rute = $this->repo->findById($id);
        if (!$rute) abort(404, 'Rute tidak ditemukan');
        return $rute;
    }

    public function create(array $data): object {
        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode_rute'])) {
            abort(409, 'Kode rute sudah digunakan');
        }
        $data = $this->resolveLokasi($data, $data['id_perusahaan']);
        $data['id_rute'] = Str::uuid()->toString();
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): object {
        $rute = $this->findOrFail($id);
        if (isset($data['kode_rute']) && $data['kode_rute'] !== $rute->kode_rute) {
            if ($this->repo->findByKode($rute->id_perusahaan, $data['kode_rute'], $id)) {
                abort(409, 'Kode rute sudah digunakan');
            }
        }
        $data = $this->resolveLokasi($data, $rute->id_perusahaan);
        return $this->repo->update($rute, $data);
    }

    public function delete(string $id): void {
        $rute = $this->findOrFail($id);
        $this->repo->delete($rute);
    }

    /**
     * Bila id_lokasi_asal/id_lokasi_tujuan dikirim, ambil lokasi milik
     * perusahaan terkait lalu isi teks asal/tujuan dari nama_lokasi.
     * 404 kalau lokasi tidak ditemukan atau beda perusahaan.
     */
    private function resolveLokasi(array $data, string $idPerusahaan): array {
        if (!empty($data['id_lokasi_asal'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_asal']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['asal'] = $lokasi->nama_lokasi;
        }
        if (!empty($data['id_lokasi_tujuan'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_tujuan']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['tujuan'] = $lokasi->nama_lokasi;
        }
        return $data;
    }
}
```

- [ ] **Step 6: Hapus Model**

Run: `rm "app/Modules/Rute/RuteModel.php"`

- [ ] **Step 7: Jalankan SEMUA test terkait, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/RuteTest.php tests/Feature/RuteLokasiTest.php`
Expected: PASS semua (7 + 7 = 14 test lolos)

- [ ] **Step 8: Commit**

Jangan commit — biarkan di working tree.

---

### Task 9: Konversi modul Klien

**Prasyarat:** Task 4 (patch Faktur) HARUS sudah selesai & lolos sebelum task ini mulai.

**Konteks tambahan:** `KlienRepository::paginateProyek()` query `ProyekModel::active()->...` — **JANGAN diubah**, Proyek bukan bagian migrasi ini, tetap Eloquent. `tests/Feature/KlienProyekTest.php` (SUDAH ADA) punya helper `makeKlien()` yang pakai `KlienModel::create(...)` — WAJIB diubah jadi `DB::table('klien')->insert(...)`, helper `makeProyek()` di file yang sama TIDAK disentuh (masih Eloquent `ProyekModel::create()`, karena Proyek tetap Eloquent).

**Files:**
- Delete: `app/Modules/Klien/KlienModel.php`
- Modify: `app/Modules/Klien/Contracts/KlienRepositoryInterface.php`
- Modify: `app/Modules/Klien/KlienRepository.php`
- Modify: `app/Modules/Klien/KlienService.php`
- Modify: `tests/Feature/KlienProyekTest.php`
- Test: `tests/Feature/KlienTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/KlienTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KlienTest extends TestCase
{
    use RefreshDatabase;

    private function makeKlien(string $idPerusahaan, string $kode = 'KLN-01', string $nama = 'Klien Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => $idPerusahaan,
            'kode_klien'    => $kode,
            'nama_klien'    => $nama,
            'aktif'         => 1,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_klien_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/klien', [
            'kode_klien' => 'KLN-BARU',
            'nama_klien' => 'PT Contoh Jaya',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nama_klien', 'PT Contoh Jaya')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('klien', ['kode_klien' => 'KLN-BARU', 'id_perusahaan' => self::PERUSAHAAN_ID]);
    }

    public function test_menolak_kode_klien_duplikat(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKlien(self::PERUSAHAAN_ID, 'KLN-DUP');

        $res = $this->postJson('/api/v1/klien', [
            'kode_klien' => 'KLN-DUP',
            'nama_klien' => 'Duplikat',
        ]);

        $res->assertStatus(409);
    }

    public function test_list_klien_hanya_menampilkan_milik_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKlien(self::PERUSAHAAN_ID, 'KLN-01', 'Milik Sendiri');
        $idLain = $this->makePerusahaanLain();
        $this->makeKlien($idLain, 'KLN-01', 'Milik Lain');

        $res = $this->getJson('/api/v1/klien');

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Milik Sendiri', $data[0]['nama_klien']);
    }

    public function test_show_klien_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKlien(self::PERUSAHAAN_ID);

        $res = $this->getJson("/api/v1/klien/{$item->id_klien}");

        $res->assertStatus(200)->assertJsonPath('data.id_klien', $item->id_klien);
    }

    public function test_update_klien_berhasil(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKlien(self::PERUSAHAAN_ID);

        $res = $this->putJson("/api/v1/klien/{$item->id_klien}", [
            'nama_klien' => 'Nama Diperbarui',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.nama_klien', 'Nama Diperbarui');
    }

    public function test_hapus_klien_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $item = $this->makeKlien(self::PERUSAHAAN_ID);

        $res = $this->deleteJson("/api/v1/klien/{$item->id_klien}");
        $res->assertStatus(200);

        $row = DB::table('klien')->where('id_klien', $item->id_klien)->first();
        $this->assertNotNull($row->dihapus_pada);
    }
}
```

- [ ] **Step 2: Jalankan test baru DAN test regresi existing (baseline sebelum diubah)**

Run: `vendor/bin/phpunit tests/Feature/KlienTest.php tests/Feature/KlienProyekTest.php tests/Feature/FakturKlienExportTest.php`
Expected: PASS semua (7 + 4 + 2 = 13 test lolos)

- [ ] **Step 3: Ganti Contract interface**

Ganti isi `app/Modules/Klien/Contracts/KlienRepositoryInterface.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Klien\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KlienRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;

    /**
     * Riwayat proyek milik satu klien, terbaru lebih dulu.
     */
    public function paginateProyek(string $idKlien, int $page, int $limit): LengthAwarePaginator;
}
```

- [ ] **Step 4: Ganti Repository (`paginateProyek()` TETAP Eloquent, jangan diubah)**

Ganti isi `app/Modules/Klien/KlienRepository.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Modules\Proyek\ProyekModel;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KlienRepository implements KlienRepositoryInterface
{
    private const COLUMNS = [
        'id_klien', 'id_perusahaan', 'kode_klien', 'nama_klien', 'email', 'telepon',
        'alamat', 'kontak_pic', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('klien')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama_klien')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('klien')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_klien', $id)
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode): ?object
    {
        return DB::table('klien')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode_klien', $kode)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_klien');
        DB::table('klien')->insert($data);
        return $this->findById($data['id_klien']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('klien')
            ->where('id_klien', $record->id_klien)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_klien);
    }

    public function delete(object $record): void
    {
        DB::table('klien')
            ->where('id_klien', $record->id_klien)
            ->update(RecordHelper::stampDelete());
    }

    public function paginateProyek(string $idKlien, int $page, int $limit): LengthAwarePaginator
    {
        return ProyekModel::active()
            ->where('id_klien', $idKlien)
            ->orderBy('dibuat_pada', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }
}
```

- [ ] **Step 5: Ganti Service (cuma type hint)**

Ganti isi `app/Modules/Klien/KlienService.php` seluruhnya jadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Klien;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;

class KlienService
{
    public function __construct(private readonly KlienRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Klien tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByKode($idPerusahaan, $data['kode_klien'])) {
            abort(409, 'Kode klien sudah digunakan');
        }

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_klien']) && $data['kode_klien'] !== $record->kode_klien) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_klien'])) {
                abort(409, 'Kode klien sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function riwayatProyek(string $idKlien, string $idPerusahaan, int $page = 1, int $limit = 10): array
    {
        $klien = $this->repo->findById($idKlien);
        if ($klien === null || $klien->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Klien tidak ditemukan');
        }

        $result = $this->repo->paginateProyek($idKlien, $page, $limit);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }
}
```

- [ ] **Step 6: Patch helper `makeKlien()` di test existing `KlienProyekTest.php`**

Di `tests/Feature/KlienProyekTest.php`, ganti import:

```php
use App\Modules\Klien\KlienModel;
use App\Modules\Proyek\ProyekModel;
```

jadi:

```php
use App\Modules\Proyek\ProyekModel;
```

(hapus `use App\Modules\Klien\KlienModel;` — `ProyekModel` TETAP dipakai, jangan dihapus.)

Ganti method `makeKlien()`:

```php
    private function makeKlien(string $nama = 'Klien Test'): KlienModel
    {
        return KlienModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => $nama,
        ]);
    }
```

jadi:

```php
    private function makeKlien(string $nama = 'Klien Test'): object
    {
        $id = (string) Str::uuid();
        DB::table('klien')->insert([
            'id_klien'      => $id,
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'kode_klien'    => 'KLN-' . Str::random(8),
            'nama_klien'    => $nama,
            'dibuat_pada'   => now(),
        ]);
        return DB::table('klien')->where('id_klien', $id)->first();
    }
```

Method `makeProyek()` di file yang sama **TIDAK disentuh** (tetap `ProyekModel::create()`).

Catatan: method test `test_menolak_riwayat_proyek_klien_perusahaan_lain()` di file ini insert klien perusahaan lain langsung via `KlienModel::create([...])` juga — cek isi file itu penuh dulu sebelum edit, pastikan SEMUA pemanggilan `KlienModel::create()` di file ini (bukan cuma di helper `makeKlien()`) ikut diganti pola yang sama.

- [ ] **Step 7: Hapus Model**

Run: `rm "app/Modules/Klien/KlienModel.php"`

- [ ] **Step 8: Jalankan SEMUA test terkait, pastikan tetap lolos**

Run: `vendor/bin/phpunit tests/Feature/KlienTest.php tests/Feature/KlienProyekTest.php tests/Feature/FakturKlienExportTest.php`
Expected: PASS semua (7 + 4 + 2 = 13 test lolos)

- [ ] **Step 9: Commit**

Jangan commit — biarkan di working tree.

---

### Task 10: Verifikasi penuh Kelompok 2

**Files:** Tidak ada file baru — task ini murni verifikasi end-to-end.

- [ ] **Step 1: Jalankan seluruh test suite backend**

Run: `vendor/bin/phpunit`
Expected: Semua test PASS — total test Kelompok 1 (166) + semua test baru/diubah Kelompok 2. Tidak boleh ada FAIL/ERROR.

- [ ] **Step 2: Rebuild image backend & restart container**

Run:
```bash
cd "D:/PROJECT-TMN/TMN-TRANSPORT-BACKEND"
docker compose -f docker-compose.local.yml build backend
docker compose -f docker-compose.local.yml up -d --no-deps backend
```
Expected: Build sukses, container restart tanpa error.

- [ ] **Step 3: Cek container tidak crash-loop & boot bersih**

Run: `sleep 15 && docker ps --filter "name=TMN-BACKEND" --format "table {{.Names}}\t{{.Status}}" && docker logs TMN-BACKEND --tail 30`
Expected: Status `Up ...`, ada `Server running on [http://0.0.0.0:4019]`, tidak ada `Class not found`.

- [ ] **Step 4: Login & smoke test semua endpoint via API asli**

Run:
```bash
TOKEN=$(curl -s -X POST http://localhost:4019/api/v1/auth/login -H "Content-Type: application/json" -d '{"username":"superadmin","password":"Password123!"}' | grep -oP '"token":"\K[^"]+')
echo "TOKEN=$TOKEN"

for path in karyawan supir rute klien; do
  echo "GET /api/v1/$path -> $(curl -s -o /dev/null -w '%{http_code}' "http://localhost:4019/api/v1/$path" -H "Authorization: Bearer $TOKEN")"
done
echo "GET /api/v1/jadwal/saya -> $(curl -s -o /dev/null -w '%{http_code}' 'http://localhost:4019/api/v1/jadwal/saya' -H "Authorization: Bearer $TOKEN")"
echo "GET /api/v1/laporan/karyawan/export/excel -> $(curl -s -o /dev/null -w '%{http_code}' 'http://localhost:4019/api/v1/laporan/karyawan/export/excel' -H "Authorization: Bearer $TOKEN")"
```
Expected: `karyawan`/`supir`/`rute`/`klien` HTTP 200. `jadwal/saya` boleh 404 (superadmin bukan supir, itu perilaku benar — konfirmasi BUKAN 500). `laporan/karyawan/export/excel` HTTP 200.

- [ ] **Step 5: Konfirmasi tidak ada sisa referensi ke 5 Model yang sudah dihapus**

Run:
```bash
grep -rln "KaryawanModel\|KaryawanExitModel\|SupirModel\|RuteModel\|KlienModel" app --include="*.php"
```
Expected: Boleh muncul beberapa hit yang HANYA komentar/docblock (mis. `KaryawanRepository.php` komentar soal jabatan/lokasi dari Kelompok 1) — verifikasi manual tiap hit BUKAN pemakaian kode nyata (import/instantiation/static call). Kalau ada pemakaian nyata, berarti ada file yang kelewat.

- [ ] **Step 6: Commit**

Jangan commit — biarkan seluruh perubahan Kelompok 2 di working tree untuk direview & di-commit manual oleh user.

---

## Ringkasan File yang Berubah

| Modul | Dihapus | Diubah |
|---|---|---|
| Pengguna/Auth (patch) | — | `app/Models/Pengguna.php`, `app/Modules/Auth/AuthRepository.php` |
| LaporanOperasional (patch) | — | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| JadwalKeberangkatan (patch) | — | `JadwalKeberangkatanController.php` |
| Faktur (patch) | — | `FakturModel.php`, `FakturController.php`, `Exports/FakturExport.php`, `resources/views/exports/faktur.blade.php` |
| Karyawan | `KaryawanModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| KaryawanExit | `KaryawanExitModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` (2×, Task 5 & 6) |
| Supir | `SupirModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| Rute | `RuteModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |
| Klien | `KlienModel.php` | `Contracts/...Interface.php`, `...Repository.php`, `...Service.php` |

**Test baru:** `AuthKaryawanTest.php`, `LaporanKaryawanExportTest.php`, `JadwalSayaTest.php`, `FakturKlienExportTest.php`, `KaryawanTest.php`, `KaryawanExitTest.php`, `SupirTest.php`, `RuteTest.php`, `KlienTest.php` (9 file baru).

**Test existing yang di-patch helper-nya:** `SupirArmadaDefaultTest.php` (`makeSupir()`), `KlienProyekTest.php` (`makeKlien()` + semua `KlienModel::create()` lain di file itu).

**Test existing yang jadi regression check (TIDAK diubah, cuma dijalankan ulang):** `KaryawanJabatanLokasiTest.php`, `RuteLokasiTest.php`.

**Tidak disentuh sama sekali:** `Vendor` + 4 modul turunannya, `Armada`, `Proyek`, `Lokasi`, `KaryawanExit`nya `Requests`/`Resource`/`Controller`, dan semua Controller/Resource/Requests di 5 modul inti (tidak pernah type-hint Model secara langsung).
