# Paket Sparepart per Jenis Perawatan & Kategori Sparepart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan (1) paket sparepart standar per kombinasi Jenis Perawatan × Jenis Kendaraan yang auto-fill daftar part di form Catat Perawatan, dan (2) kategori sparepart untuk pengelompokan data master sparepart — sesuai spec `docs/superpowers/specs/2026-07-19-paket-perawatan-sparepart-kategori-design.md`.

**Architecture:** Dua modul backend baru (`KategoriSparepart`, `PaketPerawatanSparepart`) mengikuti pola modular murni Query Builder yang sudah dipakai di seluruh backend (Controller → Service → Repository → Contract interface), tanpa Eloquent. Modul `Sparepart` yang sudah ada diperluas dengan kolom `id_kategori_sparepart`. Frontend menambah 2 pasang halaman admin (list + baru + detail) dan mengintegrasikan auto-fill ke form Catat Perawatan yang sudah ada.

**Tech Stack:** Laravel 11 (Query Builder, Sanctum, FormRequest, JsonResource), PHPUnit + RefreshDatabase, Next.js 15 App Router, TypeScript, Axios via `/api/proxy`.

## Global Constraints

- Semua query backend scope ke `id_perusahaan` milik user yang login — tidak ada pengecualian.
- Soft delete via `dihapus_pada` (DATETIME NULL) — selalu `whereNull('dihapus_pada')` di query baca, gunakan `RecordHelper::stampDelete()` untuk hapus.
- Audit columns wajib di semua tabel baru: `dibuat_pada, dibuat_oleh, diubah_pada, diubah_oleh, dihapus_pada, dihapus_oleh` via `MigrationHelper::auditColumns($table)`.
- Response API selalu lewat `App\Helpers\ApiResponse` (`success`, `paginated`) — tidak pernah `response()->json()` langsung.
- **Tenant isolation pada `findOrFail` WAJIB membandingkan `id_perusahaan`** (pola `IntervalPerawatanService::findOrFail`, BUKAN pola `JenisPerawatanService::findOrFail` yang tidak memvalidasi `id_perusahaan` sama sekali — itu bug pre-existing di luar cakupan plan ini, jangan ditiru).
- Uniqueness kombinasi (misalnya `id_jenis_perawatan + id_jenis_kendaraan + id_sparepart`) divalidasi di level Service via query, BUKAN constraint database (pola `IntervalPerawatanService::create` / `SparepartService::create`).
- Tidak boleh menjalankan `git commit` — semua task berhenti di "beri tahu user file mana yang berubah", commit dilakukan manual oleh user.
- Tidak boleh menjalankan migrasi/build/docker terhadap environment dev user secara otomatis — user yang menjalankan `php artisan migrate` dan `npm run dev`/build sendiri. Verifikasi test cukup dengan `vendor/bin/phpunit --filter=...` (test env terpisah, in-memory/SQLite via `RefreshDatabase`, aman dijalankan).
- Menu baru ditempatkan di grup "Pemeliharaan" (`id_menu = m0000001-0000-4000-8000-000000000080`), BUKAN "Data Master" ataupun "Operasional" — grup ini berisi Perawatan Armada, Dokumen Armada, Jenis Perawatan, Spare Part, Interval Perawatan (lihat `database/migrations/2026_07_17_000006_seed_menu_grup_pemeliharaan.php`).
- ID UUID menu baru yang dipakai plan ini (dicek tidak collide dengan ID manapun di live dev DB): `m0000001-0000-4000-8000-000000000083` (Paket Sparepart), `m0000001-0000-4000-8000-000000000084` (Kategori Sparepart).
- `bootstrap/providers.php` diurutkan alfabetis oleh nama kelas — provider baru harus disisipkan di posisi alfabetis yang benar, bukan ditambahkan di akhir file.

---

## Task 1: Modul Kategori Sparepart + kolom `id_kategori_sparepart` di tabel `sparepart`

**Files:**
- Create: `database/migrations/2026_07_19_100003_create_kategori_sparepart_table.php`
- Create: `database/migrations/2026_07_19_100004_add_kategori_to_sparepart_table.php`
- Create: `database/migrations/2026_07_19_100005_seed_menu_kategori_sparepart.php`
- Create: `app/Modules/KategoriSparepart/Contracts/KategoriSparepartRepositoryInterface.php`
- Create: `app/Modules/KategoriSparepart/KategoriSparepartRepository.php`
- Create: `app/Modules/KategoriSparepart/KategoriSparepartService.php`
- Create: `app/Modules/KategoriSparepart/KategoriSparepartController.php`
- Create: `app/Modules/KategoriSparepart/Requests/StoreKategoriSparepartRequest.php`
- Create: `app/Modules/KategoriSparepart/Requests/UpdateKategoriSparepartRequest.php`
- Create: `app/Modules/KategoriSparepart/Resources/KategoriSparepartResource.php`
- Create: `app/Modules/KategoriSparepart/KategoriSparepartServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/KategoriSparepartTest.php`

**Interfaces:**
- Consumes: `App\Support\RecordHelper::stampCreate/stampUpdate/stampDelete`, `App\Helpers\ApiResponse::success/paginated`, tabel `sparepart` (untuk cek pemakaian sebelum hapus).
- Produces: tabel `kategori_sparepart` (`id_kategori_sparepart, id_perusahaan, nama, aktif` + audit), kolom `sparepart.id_kategori_sparepart` (nullable char(36)) — dipakai Task 2. Endpoint `GET/POST/PUT/DELETE /api/v1/kategori-sparepart[/{id}]`.

- [ ] **Step 1: Migration tabel `kategori_sparepart`**

```php
<?php
// database/migrations/2026_07_19_100003_create_kategori_sparepart_table.php
declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori_sparepart', function (Blueprint $table) {
            $table->char('id_kategori_sparepart', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 100);
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
            $table->index(['id_perusahaan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_sparepart');
    }
};
```

- [ ] **Step 2: Migration kolom `id_kategori_sparepart` di `sparepart`**

```php
<?php
// database/migrations/2026_07_19_100004_add_kategori_to_sparepart_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sparepart', function (Blueprint $table) {
            $table->char('id_kategori_sparepart', 36)->nullable()->after('nama');
            $table->index(['id_kategori_sparepart']);
        });
    }

    public function down(): void
    {
        Schema::table('sparepart', function (Blueprint $table) {
            $table->dropIndex(['id_kategori_sparepart']);
            $table->dropColumn('id_kategori_sparepart');
        });
    }
};
```

- [ ] **Step 3: Migration seed menu "Kategori Sparepart"**

```php
<?php
// database/migrations/2026_07_19_100005_seed_menu_kategori_sparepart.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idKategoriSparepart = 'm0000001-0000-4000-8000-000000000084';
    private string $idGrupPemeliharaan  = 'm0000001-0000-4000-8000-000000000080';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idKategoriSparepart, 'nama_menu' => 'Kategori Sparepart', 'path' => '/kategori-sparepart',
                'icon' => 'tag', 'id_menu_induk' => $this->idGrupPemeliharaan, 'urutan' => 9,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idKategoriSparepart, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idKategoriSparepart)->delete();
        DB::table('menu')->where('id_menu', $this->idKategoriSparepart)->delete();
    }
};
```

- [ ] **Step 4: Contract interface**

```php
<?php
// app/Modules/KategoriSparepart/Contracts/KategoriSparepartRepositoryInterface.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface KategoriSparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function countActiveUsage(string $idKategoriSparepart): int;
}
```

- [ ] **Step 5: Repository**

```php
<?php
// app/Modules/KategoriSparepart/KategoriSparepartRepository.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Modules\KategoriSparepart\Contracts\KategoriSparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class KategoriSparepartRepository implements KategoriSparepartRepositoryInterface
{
    private const COLUMNS = [
        'id_kategori_sparepart', 'id_perusahaan', 'nama', 'aktif',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('kategori_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama')
            ->paginate($limit, self::COLUMNS, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('kategori_sparepart')
            ->select(self::COLUMNS)
            ->whereNull('dihapus_pada')
            ->where('id_kategori_sparepart', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_kategori_sparepart');
        DB::table('kategori_sparepart')->insert($data);
        return $this->findById($data['id_kategori_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('kategori_sparepart')
            ->where('id_kategori_sparepart', $record->id_kategori_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_kategori_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('kategori_sparepart')
            ->where('id_kategori_sparepart', $record->id_kategori_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function countActiveUsage(string $idKategoriSparepart): int
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_kategori_sparepart', $idKategoriSparepart)
            ->count();
    }
}
```

- [ ] **Step 6: Service (findOrFail scoped ke `id_perusahaan`)**

```php
<?php
// app/Modules/KategoriSparepart/KategoriSparepartService.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Modules\KategoriSparepart\Contracts\KategoriSparepartRepositoryInterface;

class KategoriSparepartService
{
    public function __construct(private readonly KategoriSparepartRepositoryInterface $repo) {}

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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Kategori sparepart tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        return $this->repo->update($record, $data);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $dipakai = $this->repo->countActiveUsage($id);
        if ($dipakai > 0) {
            abort(422, "Kategori sparepart masih dipakai di {$dipakai} spare part aktif, tidak bisa dihapus");
        }

        $this->repo->delete($record);
    }
}
```

- [ ] **Step 7: Requests**

```php
<?php
// app/Modules/KategoriSparepart/Requests/StoreKategoriSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKategoriSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'  => ['required', 'string', 'max:100'],
            'aktif' => ['sometimes', 'boolean'],
        ];
    }
}
```

```php
<?php
// app/Modules/KategoriSparepart/Requests/UpdateKategoriSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKategoriSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'  => ['sometimes', 'string', 'max:100'],
            'aktif' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 8: Resource**

```php
<?php
// app/Modules/KategoriSparepart/Resources/KategoriSparepartResource.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class KategoriSparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_kategori_sparepart' => $this->id_kategori_sparepart,
            'id_perusahaan'         => $this->id_perusahaan,
            'nama'                  => $this->nama,
            'aktif'                 => (bool) $this->aktif,
            'dibuat_pada'           => $this->dibuat_pada,
            'diubah_pada'           => $this->diubah_pada,
        ];
    }
}
```

- [ ] **Step 9: Controller**

```php
<?php
// app/Modules/KategoriSparepart/KategoriSparepartController.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Helpers\ApiResponse;
use App\Modules\KategoriSparepart\Requests\StoreKategoriSparepartRequest;
use App\Modules\KategoriSparepart\Requests\UpdateKategoriSparepartRequest;
use App\Modules\KategoriSparepart\Resources\KategoriSparepartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KategoriSparepartController extends Controller
{
    public function __construct(private readonly KategoriSparepartService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10)
        );

        return ApiResponse::paginated(
            KategoriSparepartResource::collection($result['data']),
            $result['meta']
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KategoriSparepartResource($record));
    }

    public function store(StoreKategoriSparepartRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan]
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new KategoriSparepartResource($record), 'Kategori sparepart berhasil dibuat', 201);
    }

    public function update(UpdateKategoriSparepartRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KategoriSparepartResource($record), 'Kategori sparepart berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Kategori sparepart berhasil dihapus');
    }
}
```

- [ ] **Step 10: ServiceProvider**

```php
<?php
// app/Modules/KategoriSparepart/KategoriSparepartServiceProvider.php
declare(strict_types=1);

namespace App\Modules\KategoriSparepart;

use App\Modules\KategoriSparepart\Contracts\KategoriSparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KategoriSparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KategoriSparepartRepositoryInterface::class, KategoriSparepartRepository::class);
        $this->app->bind(KategoriSparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::apiResource('kategori-sparepart', KategoriSparepartController::class)
                    ->parameters(['kategori-sparepart' => 'id']);
            });
    }
}
```

- [ ] **Step 11: Registrasi provider di `bootstrap/providers.php`**

Sisipkan baris berikut tepat setelah `App\Modules\KaryawanExit\KaryawanExitServiceProvider::class,` dan sebelum `App\Modules\Klien\KlienServiceProvider::class,` (urutan alfabetis):

```php
    App\Modules\KategoriSparepart\KategoriSparepartServiceProvider::class,
```

- [ ] **Step 12: Test**

```php
<?php
// tests/Feature/KategoriSparepartTest.php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KategoriSparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeKategori(string $nama = 'Oli & Pelumas', ?string $idPerusahaan = null): object
    {
        $id = (string) Str::uuid();
        DB::table('kategori_sparepart')->insert([
            'id_kategori_sparepart' => $id,
            'id_perusahaan'         => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'nama'                  => $nama,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);
        return DB::table('kategori_sparepart')->where('id_kategori_sparepart', $id)->first();
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_create_kategori_sparepart_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/kategori-sparepart', ['nama' => 'Filter']);

        $res->assertStatus(201)
            ->assertJsonPath('data.nama', 'Filter')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('kategori_sparepart', [
            'nama' => 'Filter', 'id_perusahaan' => self::PERUSAHAAN_ID,
        ]);
    }

    public function test_list_scoped_ke_perusahaan_sendiri(): void
    {
        $this->actingAsRole('ADMIN');
        $this->makeKategori('Milik Sendiri');
        $this->makeKategori('Milik Orang', $this->makePerusahaanLain());

        $res = $this->getJson('/api/v1/kategori-sparepart');

        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Milik Sendiri', $res->json('data.0.nama'));
    }

    public function test_update_dan_show_kategori_sparepart(): void
    {
        $this->actingAsRole('ADMIN');
        $kategori = $this->makeKategori();

        $resUpdate = $this->putJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}", [
            'nama' => 'Oli & Pelumas Mesin', 'aktif' => false,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('data.nama', 'Oli & Pelumas Mesin')
            ->assertJsonPath('data.aktif', false);

        $resShow = $this->getJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}");
        $resShow->assertStatus(200)->assertJsonPath('data.nama', 'Oli & Pelumas Mesin');
    }

    public function test_delete_kategori_sparepart_soft_delete(): void
    {
        $this->actingAsRole('ADMIN');
        $kategori = $this->makeKategori();

        $res = $this->deleteJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}");
        $res->assertStatus(200);

        $this->assertSoftDeleted('kategori_sparepart', ['id_kategori_sparepart' => $kategori->id_kategori_sparepart]);
        $this->getJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}")->assertStatus(404);
    }

    public function test_delete_ditolak_jika_masih_dipakai_sparepart(): void
    {
        $this->actingAsRole('ADMIN');
        $kategori = $this->makeKategori();
        DB::table('sparepart')->insert([
            'id_sparepart'          => (string) Str::uuid(),
            'id_perusahaan'         => self::PERUSAHAAN_ID,
            'id_kategori_sparepart' => $kategori->id_kategori_sparepart,
            'kode'                  => 'SP-KAT-001',
            'nama'                  => 'Oli Mesin Diesel 15W-40',
            'satuan'                => 'liter',
            'harga_standar'         => 60000,
            'stok'                  => 0,
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);

        $res = $this->deleteJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}");

        $res->assertStatus(422);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $kategori = $this->makeKategori('Milik Orang', $lain);

        $this->getJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}")->assertStatus(404);
        $this->putJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}", ['nama' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/v1/kategori-sparepart/{$kategori->id_kategori_sparepart}")->assertStatus(404);
    }
}
```

- [ ] **Step 13: Jalankan test**

Run: `vendor/bin/phpunit --filter=KategoriSparepartTest`
Expected: `OK (6 tests, ...)` semua PASS.

- [ ] **Step 14: Commit**

```bash
git add database/migrations/2026_07_19_100003_create_kategori_sparepart_table.php \
        database/migrations/2026_07_19_100004_add_kategori_to_sparepart_table.php \
        database/migrations/2026_07_19_100005_seed_menu_kategori_sparepart.php \
        app/Modules/KategoriSparepart bootstrap/providers.php \
        tests/Feature/KategoriSparepartTest.php
git commit -m "feat: tambah modul Kategori Sparepart"
```

---

## Task 2: Surface `id_kategori_sparepart` di modul Sparepart (Request/Resource/Repository/list filter)

**Files:**
- Modify: `app/Modules/Sparepart/Requests/StoreSparepartRequest.php`
- Modify: `app/Modules/Sparepart/Requests/UpdateSparepartRequest.php`
- Modify: `app/Modules/Sparepart/Resources/SparepartResource.php`
- Modify: `app/Modules/Sparepart/Contracts/SparepartRepositoryInterface.php`
- Modify: `app/Modules/Sparepart/SparepartRepository.php`
- Modify: `app/Modules/Sparepart/SparepartService.php`
- Modify: `app/Modules/Sparepart/SparepartController.php`
- Modify: `tests/Feature/SparepartTest.php`

**Interfaces:**
- Consumes: tabel `kategori_sparepart` dan kolom `sparepart.id_kategori_sparepart` dari Task 1.
- Produces: `SparepartResource` dengan field `id_kategori_sparepart` & `nama_kategori_sparepart`; `GET /api/v1/sparepart?id_kategori_sparepart=` untuk filter — dipakai Task 7 (frontend).

- [ ] **Step 1: Tambah validasi di Store/Update Request**

Edit `app/Modules/Sparepart/Requests/StoreSparepartRequest.php`, tambahkan baris berikut ke `rules()` (setelah `'nama'`):

```php
            'id_kategori_sparepart' => ['sometimes', 'nullable', 'string', 'max:36'],
```

Lakukan perubahan yang sama persis di `app/Modules/Sparepart/Requests/UpdateSparepartRequest.php`.

- [ ] **Step 2: Update Resource**

Edit `app/Modules/Sparepart/Resources/SparepartResource.php`, tambahkan 2 field setelah `'nama' => $this->nama,`:

```php
            'id_kategori_sparepart'   => $this->id_kategori_sparepart,
            'nama_kategori_sparepart' => $this->nama_kategori_sparepart ?? null,
```

- [ ] **Step 3: Update Contract interface**

Ganti isi `app/Modules/Sparepart/Contracts/SparepartRepositoryInterface.php` menjadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface SparepartRepositoryInterface
{
    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategoriSparepart = null): LengthAwarePaginator;
    public function findById(string $id): ?object;
    public function findByIdForUpdate(string $id): ?object;
    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object;
    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;
    public function countActiveUsage(string $idSparepart): int;
    public function setStok(string $id, int $stokBaru): void;
    public function insertMutasi(array $data): void;
    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator;
}
```

- [ ] **Step 4: Refactor Repository — join `kategori_sparepart` untuk nama**

Ganti isi `app/Modules/Sparepart/SparepartRepository.php` menjadi:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Sparepart;

use App\Modules\Sparepart\Contracts\SparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SparepartRepository implements SparepartRepositoryInterface
{
    private const DETAIL_SELECT = [
        'sparepart.id_sparepart', 'sparepart.id_perusahaan', 'sparepart.kode', 'sparepart.nama',
        'sparepart.id_kategori_sparepart', 'sparepart.satuan', 'sparepart.harga_standar', 'sparepart.stok',
        'sparepart.aktif', 'sparepart.dibuat_pada', 'sparepart.dibuat_oleh',
        'sparepart.diubah_pada', 'sparepart.diubah_oleh', 'sparepart.dihapus_pada', 'sparepart.dihapus_oleh',
        'kategori_sparepart.nama as nama_kategori_sparepart',
    ];

    private const MUTASI_COLUMNS = [
        'id_mutasi', 'id_sparepart', 'jenis', 'qty', 'harga', 'id_perawatan', 'keterangan', 'tanggal',
        'dibuat_pada', 'dibuat_oleh', 'diubah_pada', 'diubah_oleh', 'dihapus_pada', 'dihapus_oleh',
    ];

    private function detailQuery()
    {
        return DB::table('sparepart')
            ->leftJoin('kategori_sparepart', 'kategori_sparepart.id_kategori_sparepart', '=', 'sparepart.id_kategori_sparepart')
            ->whereNull('sparepart.dihapus_pada')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $search, ?string $idKategoriSparepart = null): LengthAwarePaginator
    {
        return $this->detailQuery()
            ->where('sparepart.id_perusahaan', $idPerusahaan)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('sparepart.nama', 'like', "%{$search}%")
                   ->orWhere('sparepart.kode', 'like', "%{$search}%");
            }))
            ->when($idKategoriSparepart, fn ($q, $v) => $q->where('sparepart.id_kategori_sparepart', $v))
            ->orderBy('sparepart.nama')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return $this->detailQuery()->where('sparepart.id_sparepart', $id)->first();
    }

    public function findByIdForUpdate(string $id): ?object
    {
        return DB::table('sparepart')
            ->select(['id_sparepart', 'nama', 'stok'])
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $id)
            ->lockForUpdate()
            ->first();
    }

    public function findByKode(string $idPerusahaan, string $kode, ?string $excludeId = null): ?object
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('kode', $kode)
            ->when($excludeId, fn ($q) => $q->where('id_sparepart', '!=', $excludeId))
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_sparepart');
        DB::table('sparepart')->insert($data);
        return $this->findById($data['id_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('sparepart')
            ->where('id_sparepart', $record->id_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $record->id_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function countActiveUsage(string $idSparepart): int
    {
        return DB::table('perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->count();
    }

    public function setStok(string $id, int $stokBaru): void
    {
        DB::table('sparepart')
            ->where('id_sparepart', $id)
            ->update(RecordHelper::stampUpdate(['stok' => $stokBaru]));
    }

    public function insertMutasi(array $data): void
    {
        DB::table('sparepart_mutasi')->insert(RecordHelper::stampCreate($data, 'id_mutasi'));
    }

    public function paginateMutasi(string $idSparepart, int $page, int $limit): LengthAwarePaginator
    {
        return DB::table('sparepart_mutasi')
            ->whereNull('dihapus_pada')
            ->where('id_sparepart', $idSparepart)
            ->orderByDesc('dibuat_pada')
            ->orderByDesc('id_mutasi')
            ->paginate($limit, self::MUTASI_COLUMNS, 'page', $page);
    }
}
```

- [ ] **Step 5: Teruskan filter dari Service**

Edit `app/Modules/Sparepart/SparepartService.php`, ganti method `list()`:

```php
    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $idKategoriSparepart = null): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $idKategoriSparepart));
    }
```

- [ ] **Step 6: Teruskan query param dari Controller**

Edit `app/Modules/Sparepart/SparepartController.php`, ganti method `index()`:

```php
    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('id_kategori_sparepart')
        );

        return ApiResponse::paginated(
            SparepartResource::collection($result['data']),
            $result['meta']
        );
    }
```

- [ ] **Step 7: Tambah test**

Tambahkan method berikut ke `tests/Feature/SparepartTest.php` (sebelum penutup class `}`):

```php
    public function test_create_dengan_kategori_dan_filter_list(): void
    {
        $this->actingAsRole('ADMIN');
        $idKategori = (string) Str::uuid();
        DB::table('kategori_sparepart')->insert([
            'id_kategori_sparepart' => $idKategori,
            'id_perusahaan'         => self::PERUSAHAAN_ID,
            'nama'                  => 'Filter',
            'aktif'                 => 1,
            'dibuat_pada'           => now(),
        ]);

        $res = $this->postJson('/api/v1/sparepart', [
            'kode' => 'SP-200', 'nama' => 'Filter Oli', 'id_kategori_sparepart' => $idKategori,
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.id_kategori_sparepart', $idKategori)
            ->assertJsonPath('data.nama_kategori_sparepart', 'Filter');

        $this->makeSparepart('SP-201', 'Kampas Rem');

        $resFilter = $this->getJson("/api/v1/sparepart?id_kategori_sparepart={$idKategori}");
        $resFilter->assertStatus(200);
        $this->assertCount(1, $resFilter->json('data'));
        $this->assertSame('SP-200', $resFilter->json('data.0.kode'));
    }
```

- [ ] **Step 8: Jalankan test**

Run: `vendor/bin/phpunit --filter=SparepartTest`
Expected: `OK (8 tests, ...)` semua PASS (7 test lama + 1 baru).

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Sparepart tests/Feature/SparepartTest.php
git commit -m "feat: tambah kategori sparepart ke modul Sparepart"
```

---

## Task 3: Modul Paket Perawatan Sparepart (BOM)

**Files:**
- Create: `database/migrations/2026_07_19_100006_create_paket_perawatan_sparepart_table.php`
- Create: `database/migrations/2026_07_19_100007_seed_menu_paket_perawatan_sparepart.php`
- Create: `app/Modules/PaketPerawatanSparepart/Contracts/PaketPerawatanSparepartRepositoryInterface.php`
- Create: `app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartRepository.php`
- Create: `app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartService.php`
- Create: `app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartController.php`
- Create: `app/Modules/PaketPerawatanSparepart/Requests/StorePaketPerawatanSparepartRequest.php`
- Create: `app/Modules/PaketPerawatanSparepart/Requests/UpdatePaketPerawatanSparepartRequest.php`
- Create: `app/Modules/PaketPerawatanSparepart/Resources/PaketPerawatanSparepartResource.php`
- Create: `app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/PaketPerawatanSparepartTest.php`
- Test: `tests/Feature/PaketPerawatanSparepartResolusiTest.php`

**Interfaces:**
- Consumes: tabel `jenis_perawatan`, `jenis_kendaraan`, `sparepart` (harus milik `id_perusahaan` yang sama — validasi seperti `IntervalPerawatanService::validasiReferensi`).
- Produces: tabel `paket_perawatan_sparepart`; endpoint CRUD `GET/POST/PUT/DELETE /api/v1/paket-perawatan-sparepart[/{id}]` dan `GET /api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan=&id_jenis_kendaraan=` yang mengembalikan **array** `{id_sparepart, nama_sparepart, satuan_sparepart, qty_standar, harga_standar}` — dipakai Task 8 (auto-fill `PerawatanForm.tsx`).

- [ ] **Step 1: Migration tabel**

```php
<?php
// database/migrations/2026_07_19_100006_create_paket_perawatan_sparepart_table.php
declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paket_perawatan_sparepart', function (Blueprint $table) {
            $table->char('id_paket_perawatan_sparepart', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->char('id_jenis_perawatan', 36);
            $table->char('id_jenis_kendaraan', 36);
            $table->char('id_sparepart', 36);
            $table->unsignedInteger('qty_standar');
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);

            $table->index(
                ['id_perusahaan', 'id_jenis_perawatan', 'id_jenis_kendaraan'],
                'paket_perawatan_sparepart_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_perawatan_sparepart');
    }
};
```

- [ ] **Step 2: Migration seed menu "Paket Sparepart"**

```php
<?php
// database/migrations/2026_07_19_100007_seed_menu_paket_perawatan_sparepart.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idPaketSparepart   = 'm0000001-0000-4000-8000-000000000083';
    private string $idGrupPemeliharaan = 'm0000001-0000-4000-8000-000000000080';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idPaketSparepart, 'nama_menu' => 'Paket Sparepart', 'path' => '/paket-perawatan-sparepart',
                'icon' => 'clipboard-list', 'id_menu_induk' => $this->idGrupPemeliharaan, 'urutan' => 8,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idPaketSparepart, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idPaketSparepart, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idPaketSparepart, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idPaketSparepart, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idPaketSparepart)->delete();
        DB::table('menu')->where('id_menu', $this->idPaketSparepart)->delete();
    }
};
```

- [ ] **Step 3: Contract interface**

```php
<?php
// app/Modules/PaketPerawatanSparepart/Contracts/PaketPerawatanSparepartRepositoryInterface.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface PaketPerawatanSparepartRepositoryInterface
{
    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisPerawatan,
        ?string $idJenisKendaraan,
    ): LengthAwarePaginator;

    public function findById(string $id): ?object;

    /** findById + kolom nama relasi (jenis perawatan, jenis kendaraan, sparepart) untuk Resource. */
    public function findDetailById(string $id): ?object;

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisPerawatan,
        string $idJenisKendaraan,
        string $idSparepart,
        ?string $excludeId = null,
    ): ?object;

    public function jenisPerawatanMilik(string $id, string $idPerusahaan): ?object;
    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object;
    public function sparepartMilik(string $id, string $idPerusahaan): ?object;

    public function create(array $data): object;
    public function update(object $record, array $data): object;
    public function delete(object $record): void;

    /** Baris aktif untuk kombinasi tertentu, join sparepart untuk nama/satuan/harga — dipakai endpoint resolusi. */
    public function resolusiList(string $idPerusahaan, string $idJenisPerawatan, string $idJenisKendaraan): array;
}
```

- [ ] **Step 4: Repository**

```php
<?php
// app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartRepository.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart;

use App\Modules\PaketPerawatanSparepart\Contracts\PaketPerawatanSparepartRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaketPerawatanSparepartRepository implements PaketPerawatanSparepartRepositoryInterface
{
    private const DETAIL_SELECT = [
        'paket_perawatan_sparepart.*',
        'jenis_perawatan.nama as nama_jenis_perawatan',
        'jenis_kendaraan.nama_jenis as nama_jenis_kendaraan',
        'sparepart.nama as nama_sparepart',
        'sparepart.satuan as satuan_sparepart',
    ];

    private function detailQuery()
    {
        return DB::table('paket_perawatan_sparepart')
            ->leftJoin('jenis_perawatan', 'jenis_perawatan.id_jenis_perawatan', '=', 'paket_perawatan_sparepart.id_jenis_perawatan')
            ->leftJoin('jenis_kendaraan', 'jenis_kendaraan.id_jenis_kendaraan', '=', 'paket_perawatan_sparepart.id_jenis_kendaraan')
            ->leftJoin('sparepart', 'sparepart.id_sparepart', '=', 'paket_perawatan_sparepart.id_sparepart')
            ->whereNull('paket_perawatan_sparepart.dihapus_pada')
            ->select(self::DETAIL_SELECT);
    }

    public function paginateByPerusahaan(
        string $idPerusahaan,
        int $page,
        int $limit,
        ?string $idJenisPerawatan,
        ?string $idJenisKendaraan,
    ): LengthAwarePaginator {
        return $this->detailQuery()
            ->where('paket_perawatan_sparepart.id_perusahaan', $idPerusahaan)
            ->when($idJenisPerawatan, fn ($q, $v) => $q->where('paket_perawatan_sparepart.id_jenis_perawatan', $v))
            ->when($idJenisKendaraan, fn ($q, $v) => $q->where('paket_perawatan_sparepart.id_jenis_kendaraan', $v))
            ->orderBy('jenis_perawatan.nama')
            ->orderBy('jenis_kendaraan.nama_jenis')
            ->orderBy('sparepart.nama')
            ->paginate($limit, self::DETAIL_SELECT, 'page', $page);
    }

    public function findById(string $id): ?object
    {
        return DB::table('paket_perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_paket_perawatan_sparepart', $id)
            ->first();
    }

    public function findDetailById(string $id): ?object
    {
        return $this->detailQuery()->where('paket_perawatan_sparepart.id_paket_perawatan_sparepart', $id)->first();
    }

    public function findByKombinasi(
        string $idPerusahaan,
        string $idJenisPerawatan,
        string $idJenisKendaraan,
        string $idSparepart,
        ?string $excludeId = null,
    ): ?object {
        return DB::table('paket_perawatan_sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_perawatan', $idJenisPerawatan)
            ->where('id_jenis_kendaraan', $idJenisKendaraan)
            ->where('id_sparepart', $idSparepart)
            ->when($excludeId !== null, fn ($q) => $q->where('id_paket_perawatan_sparepart', '!=', $excludeId))
            ->first();
    }

    public function jenisPerawatanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_perawatan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_perawatan', $id)
            ->first();
    }

    public function jenisKendaraanMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('jenis_kendaraan')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_jenis_kendaraan', $id)
            ->first();
    }

    public function sparepartMilik(string $id, string $idPerusahaan): ?object
    {
        return DB::table('sparepart')
            ->whereNull('dihapus_pada')
            ->where('id_perusahaan', $idPerusahaan)
            ->where('id_sparepart', $id)
            ->first();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_paket_perawatan_sparepart');
        DB::table('paket_perawatan_sparepart')->insert($data);
        return $this->findById($data['id_paket_perawatan_sparepart']);
    }

    public function update(object $record, array $data): object
    {
        DB::table('paket_perawatan_sparepart')
            ->where('id_paket_perawatan_sparepart', $record->id_paket_perawatan_sparepart)
            ->update(RecordHelper::stampUpdate($data));
        return $this->findById($record->id_paket_perawatan_sparepart);
    }

    public function delete(object $record): void
    {
        DB::table('paket_perawatan_sparepart')
            ->where('id_paket_perawatan_sparepart', $record->id_paket_perawatan_sparepart)
            ->update(RecordHelper::stampDelete());
    }

    public function resolusiList(string $idPerusahaan, string $idJenisPerawatan, string $idJenisKendaraan): array
    {
        return DB::table('paket_perawatan_sparepart')
            ->join('sparepart', 'sparepart.id_sparepart', '=', 'paket_perawatan_sparepart.id_sparepart')
            ->whereNull('paket_perawatan_sparepart.dihapus_pada')
            ->whereNull('sparepart.dihapus_pada')
            ->where('paket_perawatan_sparepart.id_perusahaan', $idPerusahaan)
            ->where('paket_perawatan_sparepart.id_jenis_perawatan', $idJenisPerawatan)
            ->where('paket_perawatan_sparepart.id_jenis_kendaraan', $idJenisKendaraan)
            ->where('paket_perawatan_sparepart.aktif', 1)
            ->where('sparepart.aktif', 1)
            ->orderBy('sparepart.nama')
            ->get([
                'sparepart.id_sparepart',
                'sparepart.nama as nama_sparepart',
                'sparepart.satuan as satuan_sparepart',
                'sparepart.harga_standar',
                'paket_perawatan_sparepart.qty_standar',
            ])
            ->map(fn ($row) => [
                'id_sparepart'   => $row->id_sparepart,
                'nama_sparepart' => $row->nama_sparepart,
                'satuan_sparepart' => $row->satuan_sparepart,
                'qty_standar'    => (int) $row->qty_standar,
                'harga_standar'  => (float) $row->harga_standar,
            ])
            ->all();
    }
}
```

- [ ] **Step 5: Service**

```php
<?php
// app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartService.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart;

use App\Modules\PaketPerawatanSparepart\Contracts\PaketPerawatanSparepartRepositoryInterface;

class PaketPerawatanSparepartService
{
    public function __construct(private readonly PaketPerawatanSparepartRepositoryInterface $repo) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $idJenisPerawatan = null,
        ?string $idJenisKendaraan = null,
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idJenisPerawatan, $idJenisKendaraan);

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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Paket sparepart tidak ditemukan');
        }
        return $record;
    }

    public function findDetailOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findDetailById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Paket sparepart tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];
        $this->validasiReferensi($data, $idPerusahaan);

        if ($this->repo->findByKombinasi($idPerusahaan, $data['id_jenis_perawatan'], $data['id_jenis_kendaraan'], $data['id_sparepart']) !== null) {
            abort(422, 'Paket untuk kombinasi jenis perawatan, jenis kendaraan, dan sparepart ini sudah ada');
        }

        $created = $this->repo->create($data);

        return $this->repo->findDetailById($created->id_paket_perawatan_sparepart);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->validasiReferensi($data, $idPerusahaan);

        $idJenisPerawatan = $data['id_jenis_perawatan'] ?? $record->id_jenis_perawatan;
        $idJenisKendaraan = $data['id_jenis_kendaraan'] ?? $record->id_jenis_kendaraan;
        $idSparepart      = $data['id_sparepart'] ?? $record->id_sparepart;

        if ($this->repo->findByKombinasi($idPerusahaan, $idJenisPerawatan, $idJenisKendaraan, $idSparepart, $id) !== null) {
            abort(422, 'Paket untuk kombinasi jenis perawatan, jenis kendaraan, dan sparepart ini sudah ada');
        }

        $this->repo->update($record, $data);

        return $this->repo->findDetailById($id);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->repo->delete($record);
    }

    public function resolusi(string $idPerusahaan, string $idJenisPerawatan, string $idJenisKendaraan): array
    {
        return $this->repo->resolusiList($idPerusahaan, $idJenisPerawatan, $idJenisKendaraan);
    }

    private function validasiReferensi(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_jenis_perawatan'])
            && $this->repo->jenisPerawatanMilik($data['id_jenis_perawatan'], $idPerusahaan) === null) {
            abort(404, 'Jenis perawatan tidak ditemukan');
        }
        if (isset($data['id_jenis_kendaraan'])
            && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
        if (isset($data['id_sparepart'])
            && $this->repo->sparepartMilik($data['id_sparepart'], $idPerusahaan) === null) {
            abort(404, 'Spare part tidak ditemukan');
        }
    }
}
```

- [ ] **Step 6: Requests**

```php
<?php
// app/Modules/PaketPerawatanSparepart/Requests/StorePaketPerawatanSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaketPerawatanSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_perawatan' => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
            'id_sparepart'       => ['required', 'string', 'max:36'],
            'qty_standar'        => ['required', 'integer', 'min:1'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
```

```php
<?php
// app/Modules/PaketPerawatanSparepart/Requests/UpdatePaketPerawatanSparepartRequest.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaketPerawatanSparepartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_jenis_perawatan' => ['sometimes', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['sometimes', 'string', 'max:36'],
            'id_sparepart'       => ['sometimes', 'string', 'max:36'],
            'qty_standar'        => ['sometimes', 'integer', 'min:1'],
            'aktif'              => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 7: Resource**

```php
<?php
// app/Modules/PaketPerawatanSparepart/Resources/PaketPerawatanSparepartResource.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaketPerawatanSparepartResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_paket_perawatan_sparepart' => $this->id_paket_perawatan_sparepart,
            'id_perusahaan'                => $this->id_perusahaan,
            'id_jenis_perawatan'           => $this->id_jenis_perawatan,
            'id_jenis_kendaraan'           => $this->id_jenis_kendaraan,
            'id_sparepart'                 => $this->id_sparepart,
            'nama_jenis_perawatan'         => $this->nama_jenis_perawatan ?? null,
            'nama_jenis_kendaraan'         => $this->nama_jenis_kendaraan ?? null,
            'nama_sparepart'               => $this->nama_sparepart ?? null,
            'satuan_sparepart'             => $this->satuan_sparepart ?? null,
            'qty_standar'                  => (int) $this->qty_standar,
            'aktif'                        => (bool) $this->aktif,
            'dibuat_pada'                  => $this->dibuat_pada,
            'diubah_pada'                  => $this->diubah_pada,
        ];
    }
}
```

- [ ] **Step 8: Controller (dengan endpoint `resolusi`)**

```php
<?php
// app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartController.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart;

use App\Helpers\ApiResponse;
use App\Modules\PaketPerawatanSparepart\Requests\StorePaketPerawatanSparepartRequest;
use App\Modules\PaketPerawatanSparepart\Requests\UpdatePaketPerawatanSparepartRequest;
use App\Modules\PaketPerawatanSparepart\Resources\PaketPerawatanSparepartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaketPerawatanSparepartController extends Controller
{
    public function __construct(private readonly PaketPerawatanSparepartService $service) {}

    public function index(Request $request): JsonResponse
    {
        $idPerusahaan = (string) $request->user()->id_perusahaan;

        $result = $this->service->list(
            $idPerusahaan,
            (int) $request->query('page', 1),
            (int) $request->query('limit', 10),
            $request->query('id_jenis_perawatan'),
            $request->query('id_jenis_kendaraan'),
        );

        return ApiResponse::paginated(PaketPerawatanSparepartResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findDetailOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PaketPerawatanSparepartResource($record));
    }

    public function store(StorePaketPerawatanSparepartRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan],
        );

        $record = $this->service->create($data);
        return ApiResponse::success(new PaketPerawatanSparepartResource($record), 'Paket sparepart berhasil ditambahkan', 201);
    }

    public function update(UpdatePaketPerawatanSparepartRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PaketPerawatanSparepartResource($record), 'Paket sparepart berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Paket sparepart berhasil dihapus');
    }

    public function resolusi(Request $request): JsonResponse
    {
        $request->validate([
            'id_jenis_perawatan' => ['required', 'string', 'max:36'],
            'id_jenis_kendaraan' => ['required', 'string', 'max:36'],
        ]);

        $items = $this->service->resolusi(
            (string) $request->user()->id_perusahaan,
            (string) $request->query('id_jenis_perawatan'),
            (string) $request->query('id_jenis_kendaraan'),
        );

        return ApiResponse::success($items);
    }
}
```

- [ ] **Step 9: ServiceProvider**

```php
<?php
// app/Modules/PaketPerawatanSparepart/PaketPerawatanSparepartServiceProvider.php
declare(strict_types=1);

namespace App\Modules\PaketPerawatanSparepart;

use App\Modules\PaketPerawatanSparepart\Contracts\PaketPerawatanSparepartRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PaketPerawatanSparepartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaketPerawatanSparepartRepositoryInterface::class, PaketPerawatanSparepartRepository::class);
        $this->app->bind(PaketPerawatanSparepartService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', 'izin:armada'])
            ->group(function () {
                // Route statis SEBELUM apiResource agar tidak tertangkap sebagai {id}.
                Route::get('paket-perawatan-sparepart/resolusi', [PaketPerawatanSparepartController::class, 'resolusi']);

                Route::apiResource('paket-perawatan-sparepart', PaketPerawatanSparepartController::class)
                    ->parameters(['paket-perawatan-sparepart' => 'id']);
            });
    }
}
```

- [ ] **Step 10: Registrasi provider di `bootstrap/providers.php`**

Sisipkan baris berikut tepat setelah `App\Modules\Notifikasi\NotifikasiServiceProvider::class,` dan sebelum `App\Modules\ParameterBok\ParameterBokServiceProvider::class,` (urutan alfabetis):

```php
    App\Modules\PaketPerawatanSparepart\PaketPerawatanSparepartServiceProvider::class,
```

- [ ] **Step 11: Test CRUD**

```php
<?php
// tests/Feature/PaketPerawatanSparepartTest.php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaketPerawatanSparepartTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisPerawatan(string $nama = 'Ganti Oli Mesin', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id, 'id_perusahaan' => $idPerusahaan, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $nama = 'CDD', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => strtoupper($nama) . '-' . Str::random(4), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama = 'Oli Mesin Diesel 15W-40', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan, 'kode' => 'SP-' . Str::random(6),
            'nama' => $nama, 'satuan' => 'liter', 'harga_standar' => 60000, 'stok' => 0, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePerusahaanLain(): string
    {
        $id = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $id, 'nama' => 'Perusahaan Lain Test', 'dibuat_pada' => now()]);
        return $id;
    }

    public function test_membuat_paket_berhasil(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_sparepart'       => $this->makeSparepart(),
            'qty_standar'        => 6,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.qty_standar', 6)
            ->assertJsonPath('data.nama_jenis_perawatan', 'Ganti Oli Mesin')
            ->assertJsonPath('data.nama_jenis_kendaraan', 'CDD')
            ->assertJsonPath('data.nama_sparepart', 'Oli Mesin Diesel 15W-40');
    }

    public function test_menolak_tanpa_field_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['id_jenis_perawatan', 'id_jenis_kendaraan', 'id_sparepart', 'qty_standar']);
    }

    public function test_menolak_duplikat_kombinasi(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $idSparepart = $this->makeSparepart();

        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idSparepart, 'qty_standar' => 6,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idSparepart, 'qty_standar' => 10,
        ]);

        $res->assertStatus(422);
    }

    public function test_menolak_referensi_perusahaan_lain(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();

        $res = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan('Ganti Oli', $lain),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_sparepart'       => $this->makeSparepart(),
            'qty_standar'        => 6,
        ]);

        $res->assertStatus(404);
    }

    public function test_update_dan_hapus_paket(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $this->makeJenisPerawatan(),
            'id_jenis_kendaraan' => $this->makeJenisKendaraan(),
            'id_sparepart'       => $this->makeSparepart(),
            'qty_standar'        => 6,
        ])->json('data.id_paket_perawatan_sparepart');

        $this->putJson("/api/v1/paket-perawatan-sparepart/{$id}", ['qty_standar' => 8])
            ->assertStatus(200)->assertJsonPath('data.qty_standar', 8);

        $this->deleteJson("/api/v1/paket-perawatan-sparepart/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('paket_perawatan_sparepart', ['id_paket_perawatan_sparepart' => $id]);
    }

    public function test_isolasi_tenant(): void
    {
        $this->actingAsRole('ADMIN');
        $lain = $this->makePerusahaanLain();
        $id = (string) Str::uuid();
        DB::table('paket_perawatan_sparepart')->insert([
            'id_paket_perawatan_sparepart' => $id,
            'id_perusahaan'                => $lain,
            'id_jenis_perawatan'           => $this->makeJenisPerawatan('Ganti Oli', $lain),
            'id_jenis_kendaraan'           => $this->makeJenisKendaraan('CDD', $lain),
            'id_sparepart'                 => $this->makeSparepart('Oli', $lain),
            'qty_standar'                  => 6,
            'aktif'                        => 1,
            'dibuat_pada'                  => now(),
        ]);

        $this->assertCount(0, $this->getJson('/api/v1/paket-perawatan-sparepart')->json('data'));
        $this->getJson("/api/v1/paket-perawatan-sparepart/{$id}")->assertStatus(404);
        $this->putJson("/api/v1/paket-perawatan-sparepart/{$id}", ['qty_standar' => 1])->assertStatus(404);
        $this->deleteJson("/api/v1/paket-perawatan-sparepart/{$id}")->assertStatus(404);
    }
}
```

- [ ] **Step 12: Test resolusi**

```php
<?php
// tests/Feature/PaketPerawatanSparepartResolusiTest.php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaketPerawatanSparepartResolusiTest extends TestCase
{
    use RefreshDatabase;

    private function makeJenisPerawatan(string $nama = 'Ganti Oli Mesin', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_perawatan')->insert([
            'id_jenis_perawatan' => $id, 'id_perusahaan' => $idPerusahaan, 'nama' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeJenisKendaraan(string $nama = 'CDD', string $idPerusahaan = self::PERUSAHAAN_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('jenis_kendaraan')->insert([
            'id_jenis_kendaraan' => $id, 'id_perusahaan' => $idPerusahaan,
            'kode_jenis' => strtoupper($nama) . '-' . Str::random(4), 'nama_jenis' => $nama, 'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makeSparepart(string $nama, string $idPerusahaan = self::PERUSAHAAN_ID, int $aktif = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('sparepart')->insert([
            'id_sparepart' => $id, 'id_perusahaan' => $idPerusahaan, 'kode' => 'SP-' . Str::random(6),
            'nama' => $nama, 'satuan' => 'liter', 'harga_standar' => 60000, 'stok' => 0, 'aktif' => $aktif, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_resolusi_mengembalikan_daftar_part_untuk_kombinasi_cocok(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $idOli = $this->makeSparepart('Oli Mesin Diesel 15W-40');
        $idFilter = $this->makeSparepart('Filter Oli');

        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idOli, 'qty_standar' => 6,
        ])->assertStatus(201);
        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idFilter, 'qty_standar' => 1,
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan={$idJenis}&id_jenis_kendaraan={$idKendaraan}");

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data'));
        $this->assertSame('Filter Oli', $res->json('data.0.nama_sparepart'));
        $this->assertSame(6, $res->json('data.1.qty_standar'));
    }

    public function test_resolusi_tanpa_kombinasi_cocok_mengembalikan_array_kosong(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan=' . $this->makeJenisPerawatan()
            . '&id_jenis_kendaraan=' . $this->makeJenisKendaraan());

        $res->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_resolusi_mengecualikan_sparepart_yang_nonaktif(): void
    {
        $this->actingAsRole('ADMIN');
        $idJenis = $this->makeJenisPerawatan();
        $idKendaraan = $this->makeJenisKendaraan();
        $idNonaktif = $this->makeSparepart('Sparepart Nonaktif', self::PERUSAHAAN_ID, 0);

        $this->postJson('/api/v1/paket-perawatan-sparepart', [
            'id_jenis_perawatan' => $idJenis, 'id_jenis_kendaraan' => $idKendaraan, 'id_sparepart' => $idNonaktif, 'qty_standar' => 1,
        ])->assertStatus(201);

        $res = $this->getJson("/api/v1/paket-perawatan-sparepart/resolusi?id_jenis_perawatan={$idJenis}&id_jenis_kendaraan={$idKendaraan}");

        $res->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_resolusi_menolak_tanpa_query_wajib(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/v1/paket-perawatan-sparepart/resolusi');

        $res->assertStatus(422);
    }
}
```

- [ ] **Step 13: Jalankan test**

Run: `vendor/bin/phpunit --filter=PaketPerawatanSparepart`
Expected: `OK (10 tests, ...)` semua PASS (6 test CRUD di `PaketPerawatanSparepartTest` + 4 test di `PaketPerawatanSparepartResolusiTest`, tidak ada FAIL/ERROR).

- [ ] **Step 14: Commit**

```bash
git add database/migrations/2026_07_19_100006_create_paket_perawatan_sparepart_table.php \
        database/migrations/2026_07_19_100007_seed_menu_paket_perawatan_sparepart.php \
        app/Modules/PaketPerawatanSparepart bootstrap/providers.php \
        tests/Feature/PaketPerawatanSparepartTest.php tests/Feature/PaketPerawatanSparepartResolusiTest.php
git commit -m "feat: tambah modul Paket Perawatan Sparepart (BOM) beserta endpoint resolusi"
```

---

## Task 4: Seed data master real (kategori, sparepart, paket)

**Files:**
- Create: `database/seeders/PerawatanSparepartMasterDataSeeder.php`

**Interfaces:**
- Consumes: tabel `kategori_sparepart` (Task 1), `sparepart` (Task 2), `paket_perawatan_sparepart` (Task 3), 6 `id_jenis_kendaraan` yang sudah ada di tenant dev (`b8f3c1a2-0000-4000-8000-000000000001`), 5 dari 10 `id_jenis_perawatan` yang sudah diseed sebelumnya oleh `PerawatanMasterDataSeeder` (kelas ini HARUS query nama Jenis Perawatan by nama, bukan hardcode UUID, karena UUID Jenis Perawatan digenerate acak oleh `PerawatanMasterDataSeeder` versi manapun yang sudah dijalankan user — lihat Step 1).
- Produces: tidak ada — seeder dijalankan manual oleh user via `php artisan db:seed --class=PerawatanSparepartMasterDataSeeder` (bukan bagian dari `DatabaseSeeder::run()`).

- [ ] **Step 1: Cek nama Jenis Perawatan sumber**

Seeder ini butuh `id_jenis_perawatan` dari 5 baris yang sudah dibuat `PerawatanMasterDataSeeder` (lihat `database/seeders/PerawatanMasterDataSeeder.php` yang sudah ada di repo): "Ganti Oli Mesin & Filter Oli", "Ganti Oli Gardan (Differential)", "Ganti Oli Transmisi", "Ganti Filter Udara", "Ganti Filter Solar (Bahan Bakar)". Seeder baru ini query by `nama` (bukan hardcode UUID) karena `PerawatanMasterDataSeeder` men-generate UUID jenis perawatan dengan pola statis (`p2000001-0000-4000-8000-00000000000{1..5}`) — gunakan UUID statis yang sama persis supaya tidak perlu query, karena lebih cepat dan konsisten dengan gaya seeder lain di repo ini.

- [ ] **Step 2: Tulis seeder**

```php
<?php
// database/seeders/PerawatanSparepartMasterDataSeeder.php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// php artisan db:seed --class=PerawatanSparepartMasterDataSeeder
// Prasyarat: PerawatanMasterDataSeeder sudah dijalankan lebih dulu (menyediakan
// 5 id_jenis_perawatan berikut dengan UUID statis p2000001-...-1..5).
class PerawatanSparepartMasterDataSeeder extends Seeder
{
    private const ID_PERUSAHAAN = 'b8f3c1a2-0000-4000-8000-000000000001';

    // UUID id_jenis_perawatan statis dari PerawatanMasterDataSeeder (sudah ada di repo).
    private const ID_JP_OLI_MESIN    = 'p2000001-0000-4000-8000-000000000001';
    private const ID_JP_OLI_GARDAN   = 'p2000001-0000-4000-8000-000000000002';
    private const ID_JP_OLI_TRANS    = 'p2000001-0000-4000-8000-000000000003';
    private const ID_JP_FILTER_UDARA = 'p2000001-0000-4000-8000-000000000004';
    private const ID_JP_FILTER_SOLAR = 'p2000001-0000-4000-8000-000000000005';

    private const ID_JK_CDD     = '8dd00ef9-918d-462b-9c64-c02a3456b76f';
    private const ID_JK_PICKUP  = 'e2000001-0000-4000-8000-000000000002';
    private const ID_JK_ENGKEL  = 'e2000001-0000-4000-8000-000000000003';
    private const ID_JK_FUSO    = 'e2000001-0000-4000-8000-000000000004';
    private const ID_JK_TRONTON = 'e2000001-0000-4000-8000-000000000005';
    private const ID_JK_WINGBOX = 'e2000001-0000-4000-8000-000000000006';

    public function run(): void
    {
        $now = now();

        // ── Kategori Sparepart ───────────────────────────────────────────
        $idKategoriOli    = 'k2000001-0000-4000-8000-000000000001';
        $idKategoriFilter = 'k2000001-0000-4000-8000-000000000002';

        DB::table('kategori_sparepart')->upsert([
            ['id_kategori_sparepart' => $idKategoriOli,    'id_perusahaan' => self::ID_PERUSAHAAN, 'nama' => 'Oli & Pelumas', 'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null],
            ['id_kategori_sparepart' => $idKategoriFilter, 'id_perusahaan' => self::ID_PERUSAHAAN, 'nama' => 'Filter',       'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null],
        ], ['id_kategori_sparepart'], ['nama', 'aktif']);

        // ── Sparepart master ─────────────────────────────────────────────
        $sparepart = [
            'oli_mesin'    => ['id' => 's2000001-0000-4000-8000-000000000001', 'kode' => 'SP-OLI-MESIN',    'nama' => 'Oli Mesin Diesel 15W-40',           'satuan' => 'liter', 'harga' => 60000,  'kategori' => $idKategoriOli],
            'oli_gardan'   => ['id' => 's2000001-0000-4000-8000-000000000002', 'kode' => 'SP-OLI-GARDAN',   'nama' => 'Oli Gardan (Gear Oil 85W-140)',     'satuan' => 'liter', 'harga' => 70000,  'kategori' => $idKategoriOli],
            'oli_transmisi'=> ['id' => 's2000001-0000-4000-8000-000000000003', 'kode' => 'SP-OLI-TRANS',    'nama' => 'Oli Transmisi',                     'satuan' => 'liter', 'harga' => 65000,  'kategori' => $idKategoriOli],
            'filter_oli'   => ['id' => 's2000001-0000-4000-8000-000000000004', 'kode' => 'SP-FILTER-OLI',   'nama' => 'Filter Oli',                        'satuan' => 'pcs',   'harga' => 85000,  'kategori' => $idKategoriFilter],
            'filter_udara' => ['id' => 's2000001-0000-4000-8000-000000000005', 'kode' => 'SP-FILTER-UDARA', 'nama' => 'Filter Udara',                      'satuan' => 'pcs',   'harga' => 150000, 'kategori' => $idKategoriFilter],
            'filter_solar' => ['id' => 's2000001-0000-4000-8000-000000000006', 'kode' => 'SP-FILTER-SOLAR', 'nama' => 'Filter Solar',                      'satuan' => 'pcs',   'harga' => 120000, 'kategori' => $idKategoriFilter],
        ];

        $sparepartRows = array_map(fn (array $s) => [
            'id_sparepart'          => $s['id'],
            'id_perusahaan'         => self::ID_PERUSAHAAN,
            'kode'                  => $s['kode'],
            'nama'                  => $s['nama'],
            'id_kategori_sparepart' => $s['kategori'],
            'satuan'                => $s['satuan'],
            'harga_standar'         => $s['harga'],
            'stok'                  => 0,
            'aktif'                 => 1,
            'dibuat_pada'           => $now,
            'dibuat_oleh'           => null,
        ], $sparepart);

        DB::table('sparepart')->upsert(
            $sparepartRows,
            ['id_sparepart'],
            ['kode', 'nama', 'id_kategori_sparepart', 'satuan', 'harga_standar', 'aktif']
        );

        // ── Paket Perawatan Sparepart (BOM) ──────────────────────────────
        // qty per jenis kendaraan: pickup, cdd, engkel, fuso, tronton, wingbox
        $paket = [
            [self::ID_JP_OLI_MESIN,    'oli_mesin',     ['pickup' => 4, 'cdd' => 6, 'engkel' => 6, 'fuso' => 12, 'tronton' => 20, 'wingbox' => 15]],
            [self::ID_JP_OLI_MESIN,    'filter_oli',    ['pickup' => 1, 'cdd' => 1, 'engkel' => 1, 'fuso' => 1,  'tronton' => 1,  'wingbox' => 1]],
            [self::ID_JP_OLI_GARDAN,   'oli_gardan',    ['pickup' => 1, 'cdd' => 2, 'engkel' => 2, 'fuso' => 4,  'tronton' => 6,  'wingbox' => 5]],
            [self::ID_JP_OLI_TRANS,    'oli_transmisi', ['pickup' => 2, 'cdd' => 3, 'engkel' => 3, 'fuso' => 6,  'tronton' => 8,  'wingbox' => 7]],
            [self::ID_JP_FILTER_UDARA, 'filter_udara',  ['pickup' => 1, 'cdd' => 1, 'engkel' => 1, 'fuso' => 1,  'tronton' => 1,  'wingbox' => 1]],
            [self::ID_JP_FILTER_SOLAR, 'filter_solar',  ['pickup' => 1, 'cdd' => 1, 'engkel' => 1, 'fuso' => 1,  'tronton' => 1,  'wingbox' => 1]],
        ];

        $idJenisKendaraan = [
            'pickup'  => self::ID_JK_PICKUP,
            'cdd'     => self::ID_JK_CDD,
            'engkel'  => self::ID_JK_ENGKEL,
            'fuso'    => self::ID_JK_FUSO,
            'tronton' => self::ID_JK_TRONTON,
            'wingbox' => self::ID_JK_WINGBOX,
        ];

        $paketRows = [];
        $urut = 0;
        foreach ($paket as [$idJenisPerawatan, $kodeSparepart, $qtyPerKendaraan]) {
            foreach ($qtyPerKendaraan as $kodeTipe => $qty) {
                $urut++;
                $paketRows[] = [
                    'id_paket_perawatan_sparepart' => sprintf('b2000001-0000-4000-8000-%012d', $urut),
                    'id_perusahaan'                => self::ID_PERUSAHAAN,
                    'id_jenis_perawatan'           => $idJenisPerawatan,
                    'id_jenis_kendaraan'           => $idJenisKendaraan[$kodeTipe],
                    'id_sparepart'                 => $sparepart[$kodeSparepart]['id'],
                    'qty_standar'                  => $qty,
                    'aktif'                        => 1,
                    'dibuat_pada'                  => $now,
                    'dibuat_oleh'                  => null,
                ];
            }
        }

        DB::table('paket_perawatan_sparepart')->upsert(
            $paketRows,
            ['id_paket_perawatan_sparepart'],
            ['qty_standar', 'aktif']
        );
    }
}
```

- [ ] **Step 3: Lint syntax**

Run: `php -l database/seeders/PerawatanSparepartMasterDataSeeder.php`
Expected: `No syntax errors detected in database/seeders/PerawatanSparepartMasterDataSeeder.php`

- [ ] **Step 4: Commit**

```bash
git add database/seeders/PerawatanSparepartMasterDataSeeder.php
git commit -m "feat: seed data master kategori, sparepart, dan paket perawatan"
```

Catatan untuk user: jalankan sendiri setelah migrasi diterapkan —
`php artisan db:seed --class=PerawatanSparepartMasterDataSeeder`
(atau via docker: `docker compose -f docker-compose.local.yml exec backend php artisan db:seed --class=PerawatanSparepartMasterDataSeeder`).

---

## Task 5: Frontend — Kategori Sparepart (service + 3 halaman)

**Files:**
- Create: `src/services/kategoriSparepart.service.ts`
- Modify: `src/constants/api.constant.ts`
- Modify: `src/constants/route.constant.ts`
- Create: `src/app/(protected-pages)/kategori-sparepart/page.tsx`
- Create: `src/app/(protected-pages)/kategori-sparepart/baru/page.tsx`
- Create: `src/app/(protected-pages)/kategori-sparepart/[id]/page.tsx`

**Interfaces:**
- Consumes: `GET/POST/PUT/DELETE /api/proxy/kategori-sparepart[/{id}]` dari Task 1.
- Produces: `kategoriSparepartService` (dipakai Task 7 untuk dropdown filter/pilih kategori di halaman Sparepart).

- [ ] **Step 1: Tambah entri di `api.constant.ts`**

Tambahkan blok berikut setelah blok `// Sparepart` (setelah baris `SPAREPART_MUTASI`):

```ts
    // Kategori Sparepart
    KATEGORI_SPAREPART:        '/api/proxy/kategori-sparepart',
    KATEGORI_SPAREPART_DETAIL: (id: string) => `/api/proxy/kategori-sparepart/${id}`,
```

- [ ] **Step 2: Tambah entri di `route.constant.ts`**

Tambahkan baris berikut setelah `SPAREPART_DETAIL: (id: string) => \`/sparepart/${id}\`,`:

```ts
    KATEGORI_SPAREPART:        '/kategori-sparepart',
    KATEGORI_SPAREPART_BARU:   '/kategori-sparepart/baru',
    KATEGORI_SPAREPART_DETAIL: (id: string) => `/kategori-sparepart/${id}`,
```

- [ ] **Step 3: Service**

```ts
// src/services/kategoriSparepart.service.ts
import axios from 'axios'
import { API_ENDPOINTS } from '@/constants/api.constant'

export interface KategoriSparepart {
    id_kategori_sparepart: string
    id_perusahaan: string
    nama: string
    aktif: boolean
    dibuat_pada: string
    diubah_pada: string | null
}

export type KategoriSparepartPayload = {
    nama: string
    aktif?: boolean
}

export const kategoriSparepartService = {
    async list(page = 1, limit = 15) {
        const { data } = await axios.get(API_ENDPOINTS.KATEGORI_SPAREPART, { params: { page, limit } })
        return data as { data: KategoriSparepart[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
    async get(id: string) {
        const { data } = await axios.get(API_ENDPOINTS.KATEGORI_SPAREPART_DETAIL(id))
        return data.data as KategoriSparepart
    },
    async create(payload: KategoriSparepartPayload) {
        const { data } = await axios.post(API_ENDPOINTS.KATEGORI_SPAREPART, payload)
        return data.data as KategoriSparepart
    },
    async update(id: string, payload: Partial<KategoriSparepartPayload>) {
        const { data } = await axios.put(API_ENDPOINTS.KATEGORI_SPAREPART_DETAIL(id), payload)
        return data.data as KategoriSparepart
    },
    async delete(id: string) {
        await axios.delete(API_ENDPOINTS.KATEGORI_SPAREPART_DETAIL(id))
    },
}
```

- [ ] **Step 4: Halaman list**

```tsx
// src/app/(protected-pages)/kategori-sparepart/page.tsx
'use client'
import { useEffect, useState, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, Input, Tag, Tooltip, toast, Notification } from '@/components/ui'
import { HiPlusCircle, HiOutlineSearch, HiOutlineX, HiOutlineEye, HiOutlineTrash } from 'react-icons/hi'
import DataTable from '@/components/shared/DataTable'
import ConfirmDialog from '@/components/shared/ConfirmDialog'
import type { ColumnDef, CellContext } from '@/components/shared/DataTable'
import { parseApiError } from '@/utils/error.util'
import { ROUTES } from '@/constants/route.constant'
import { kategoriSparepartService, KategoriSparepart } from '@/services/kategoriSparepart.service'

export default function KategoriSparepartPage() {
    const router = useRouter()
    const [list, setList]             = useState<KategoriSparepart[]>([])
    const [loading, setLoading]       = useState(false)
    const [submitting, setSubmitting] = useState(false)
    const [searchInput, setSearchInput] = useState('')
    const [search, setSearch]           = useState('')
    const [currentPage, setCurrentPage] = useState(1)
    const [pageSize, setPageSize]       = useState(15)
    const [total, setTotal]             = useState(0)
    const [deleteTarget, setDeleteTarget] = useState<KategoriSparepart | null>(null)

    const fetchData = useCallback(async () => {
        setLoading(true)
        try {
            const res = await kategoriSparepartService.list(currentPage)
            setList(res.data)
            setTotal(res.meta.total)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [currentPage])

    useEffect(() => { fetchData() }, [fetchData])

    const handleSearchSubmit = () => { setSearch(searchInput); setCurrentPage(1) }
    const handleSearchClear  = () => { setSearchInput(''); setSearch(''); setCurrentPage(1) }

    const handleDelete = async () => {
        if (!deleteTarget) return
        setSubmitting(true)
        try {
            await kategoriSparepartService.delete(deleteTarget.id_kategori_sparepart)
            toast.push(<Notification type="success" title="Kategori sparepart berhasil dihapus" />)
            setDeleteTarget(null)
            fetchData()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSubmitting(false)
        }
    }

    const filteredList = list.filter(l => {
        if (!search) return true
        return l.nama.toLowerCase().includes(search.toLowerCase())
    })

    const columns: ColumnDef<KategoriSparepart>[] = [
        { header: 'No', id: 'no', size: 60,
            cell: ({ row }: CellContext<KategoriSparepart, unknown>) => (currentPage - 1) * pageSize + row.index + 1 },
        { header: 'Nama', accessorKey: 'nama', size: 260,
            cell: ({ row }: CellContext<KategoriSparepart, unknown>) => <span className="font-semibold">{row.original.nama}</span>,
        },
        { header: 'Status Aktif', accessorKey: 'aktif', size: 110,
            cell: ({ row }: CellContext<KategoriSparepart, unknown>) => (
                <Tag className={row.original.aktif
                    ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-100'
                    : 'bg-red-100 text-red-500 dark:bg-red-500/20 dark:text-red-100'}>
                    {row.original.aktif ? 'Aktif' : 'Nonaktif'}
                </Tag>
            ),
        },
        { header: '', id: 'action', size: 90,
            cell: ({ row }: CellContext<KategoriSparepart, unknown>) => (
                <div className="flex items-center justify-end gap-2">
                    <Tooltip title="Detail">
                        <span className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-500/20 dark:text-blue-300 dark:hover:bg-blue-500/30 transition-colors"
                            onClick={() => router.push(ROUTES.KATEGORI_SPAREPART_DETAIL(row.original.id_kategori_sparepart))}>
                            <HiOutlineEye className="text-lg" />
                        </span>
                    </Tooltip>
                    <Tooltip title="Hapus">
                        <span className="cursor-pointer inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-500 hover:bg-red-100 dark:bg-red-500/20 dark:text-red-400 dark:hover:bg-red-500/30 transition-colors"
                            onClick={() => setDeleteTarget(row.original)}>
                            <HiOutlineTrash className="text-lg" />
                        </span>
                    </Tooltip>
                </div>
            ),
        },
    ]

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="font-bold">Kategori Sparepart</h3>
                    <p className="text-gray-500 text-sm mt-0.5">Data master kategori spare part</p>
                </div>
                <Button variant="solid" size="sm" icon={<HiPlusCircle />}
                    onClick={() => router.push(ROUTES.KATEGORI_SPAREPART_BARU)}>
                    Tambah Kategori
                </Button>
            </div>
            <Card bodyClass="p-0">
                <div className="flex items-center gap-3 px-4 py-3">
                    <Input className="flex-1" placeholder="Cari nama kategori... (tekan Enter)"
                        suffix={searchInput
                            ? <HiOutlineX className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchClear} />
                            : <HiOutlineSearch className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchSubmit} />}
                        value={searchInput}
                        onChange={e => setSearchInput(e.target.value)}
                        onKeyDown={e => { if (e.key === 'Enter') handleSearchSubmit() }} />
                </div>
                <DataTable columns={columns} data={filteredList as unknown[]} loading={loading}
                    noData={!loading && filteredList.length === 0}
                    pagingData={{ total, pageIndex: currentPage, pageSize }}
                    onPaginationChange={setCurrentPage}
                    onSelectChange={size => { setPageSize(size); setCurrentPage(1) }} />
            </Card>

            <ConfirmDialog isOpen={!!deleteTarget} type="danger" title="Hapus Kategori Sparepart?"
                confirmText="Ya, Hapus" cancelText="Batal"
                confirmButtonProps={{ loading: submitting, customColorClass: () => 'bg-red-500 hover:bg-red-600 active:bg-red-700 text-white border-red-500' }}
                onClose={() => setDeleteTarget(null)} onCancel={() => setDeleteTarget(null)} onConfirm={handleDelete}>
                <p className="text-sm">Kategori <span className="font-semibold">&ldquo;{deleteTarget?.nama}&rdquo;</span> akan dihapus secara permanen. Tindakan ini tidak dapat dibatalkan.</p>
            </ConfirmDialog>
        </div>
    )
}
```

- [ ] **Step 5: Halaman Tambah**

```tsx
// src/app/(protected-pages)/kategori-sparepart/baru/page.tsx
'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, FormItem, Input, toast, Notification } from '@/components/ui'
import { HiArrowLeft } from 'react-icons/hi'
import { parseApiError } from '@/utils/error.util'
import { ROUTES } from '@/constants/route.constant'
import { kategoriSparepartService } from '@/services/kategoriSparepart.service'

export default function KategoriSparepartBaruPage() {
    const router = useRouter()
    const [form, setForm] = useState({ nama: '' })
    const [loading, setLoading] = useState(false)
    const [errors, setErrors] = useState<Record<string, string>>({})

    const validate = () => {
        const e: Record<string, string> = {}
        if (!form.nama.trim()) e.nama = 'Nama kategori wajib diisi'
        setErrors(e)
        return Object.keys(e).length === 0
    }

    const handleSubmit = async () => {
        if (!validate()) {
            toast.push(<Notification type="danger" title="Periksa kembali data yang belum lengkap" />)
            window.scrollTo({ top: 0, behavior: 'smooth' })
            return
        }
        setLoading(true)
        try {
            await kategoriSparepartService.create({ nama: form.nama })
            toast.push(<Notification type="success" title="Kategori sparepart berhasil ditambahkan" />)
            router.push(ROUTES.KATEGORI_SPAREPART)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <button type="button" onClick={() => router.back()}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors">
                    <HiArrowLeft className="text-xl" />
                </button>
                <div>
                    <h3 className="font-bold">Tambah Kategori Sparepart</h3>
                    <p className="text-gray-500 text-sm mt-0.5">Daftarkan kategori spare part baru</p>
                </div>
            </div>
            <Card>
                <form onSubmit={e => { e.preventDefault(); handleSubmit() }}>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                    <FormItem label="Nama Kategori" asterisk invalid={!!errors.nama} errorMessage={errors.nama}>
                        <Input placeholder="Contoh: Oli & Pelumas, Filter" value={form.nama} invalid={!!errors.nama}
                            onChange={e => setForm(p => ({ ...p, nama: e.target.value }))} />
                    </FormItem>
                </div>
                <div className="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <Button type="button" variant="plain" onClick={() => router.back()}>Batal</Button>
                    <Button type="submit" variant="solid" loading={loading}>Simpan</Button>
                </div>
                </form>
            </Card>
        </div>
    )
}
```

- [ ] **Step 6: Halaman Detail/Edit**

```tsx
// src/app/(protected-pages)/kategori-sparepart/[id]/page.tsx
'use client'
import { use, useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, FormItem, Input, toast, Notification } from '@/components/ui'
import Select from '@/components/ui/Select'
import { HiArrowLeft, HiOutlinePencilAlt } from 'react-icons/hi'
import { parseApiError } from '@/utils/error.util'
import { ROUTES } from '@/constants/route.constant'
import { kategoriSparepartService, KategoriSparepart } from '@/services/kategoriSparepart.service'

const AKTIF_OPTIONS = [{ value: 'true', label: 'Aktif' }, { value: 'false', label: 'Nonaktif' }]

export default function KategoriSparepartDetailPage({ params }: { params: Promise<{ id: string }> }) {
    const { id } = use(params)
    const router = useRouter()

    const [data, setData]     = useState<KategoriSparepart | null>(null)
    const [loading, setLoading] = useState(true)
    const [editing, setEditing] = useState(false)
    const [form, setForm]       = useState<Partial<KategoriSparepart>>({})
    const [errors, setErrors]   = useState<Partial<Record<keyof typeof form, string>>>({})
    const [saving, setSaving]   = useState(false)

    useEffect(() => {
        kategoriSparepartService.get(id)
            .then(d => { setData(d); setForm(d) })
            .catch(err => toast.push(<Notification type="danger" title={parseApiError(err)} />))
            .finally(() => setLoading(false))
    }, [id])

    const validate = () => {
        const e: Partial<Record<keyof typeof form, string>> = {}
        if (!form.nama?.trim()) e.nama = 'Nama kategori wajib diisi'
        setErrors(e)
        return Object.keys(e).length === 0
    }

    const handleSave = async () => {
        if (!validate()) {
            toast.push(<Notification type="danger" title="Periksa kembali data yang belum lengkap" />)
            window.scrollTo({ top: 0, behavior: 'smooth' })
            return
        }
        setSaving(true)
        try {
            const updated = await kategoriSparepartService.update(id, { nama: form.nama, aktif: form.aktif })
            setData(updated); setEditing(false); setErrors({})
            toast.push(<Notification type="success" title="Kategori sparepart berhasil diperbarui" />)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    if (loading) return <div className="p-6 text-gray-500">Memuat...</div>
    if (!data) return <div className="p-6 text-red-500">Kategori sparepart tidak ditemukan.</div>

    const initial = data.nama?.charAt(0).toUpperCase() ?? 'K'

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <button type="button" onClick={() => router.push(ROUTES.KATEGORI_SPAREPART)}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors">
                    <HiArrowLeft className="text-xl" />
                </button>
                <div>
                    <h3 className="font-bold">{data.nama}</h3>
                    <p className="text-gray-500 text-sm mt-0.5">Detail kategori spare part</p>
                </div>
            </div>

            <Card>
                {!editing ? (
                    <>
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-4">
                                <div className="flex items-center justify-center w-14 h-14 rounded-2xl bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-400 font-bold text-xl flex-shrink-0 select-none">
                                    {initial}
                                </div>
                                <p className="font-semibold text-base text-gray-800 dark:text-gray-100 leading-tight">{data.nama}</p>
                            </div>
                            <div className="flex items-center gap-2 flex-shrink-0">
                                <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${data.aktif ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-500'}`}>
                                    {data.aktif ? 'Aktif' : 'Nonaktif'}
                                </span>
                                <Button variant="solid" size="sm" icon={<HiOutlinePencilAlt />} onClick={() => setEditing(true)}>Edit</Button>
                            </div>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="flex items-center gap-4 mb-5">
                            <div className="flex items-center justify-center w-14 h-14 rounded-2xl bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-400 font-bold text-xl flex-shrink-0 select-none">
                                {form.nama?.charAt(0).toUpperCase() ?? initial}
                            </div>
                            <div>
                                <p className="font-semibold text-base text-gray-800 dark:text-gray-100">Edit Kategori Sparepart</p>
                                <p className="text-sm text-gray-500 mt-0.5">Perbarui informasi kategori di bawah ini</p>
                            </div>
                        </div>
                        <div className="border-t border-gray-100 dark:border-gray-700 mb-5" />
                        <form onSubmit={e => { e.preventDefault(); handleSave() }}>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                            <FormItem label="Nama Kategori" asterisk invalid={!!errors.nama} errorMessage={errors.nama}>
                                <Input value={form.nama ?? ''} invalid={!!errors.nama} onChange={e => setForm(p => ({ ...p, nama: e.target.value }))} />
                            </FormItem>
                            <FormItem label="Status">
                                <Select isSearchable={false} options={AKTIF_OPTIONS}
                                    value={AKTIF_OPTIONS.find(o => o.value === String(form.aktif)) ?? null}
                                    onChange={opt => setForm(p => ({ ...p, aktif: opt?.value === 'true' }))} />
                            </FormItem>
                        </div>
                        <div className="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Button type="button" variant="plain" onClick={() => { setEditing(false); setForm(data); setErrors({}) }}>Batal</Button>
                            <Button type="submit" variant="solid" loading={saving}>Simpan</Button>
                        </div>
                        </form>
                    </>
                )}
            </Card>
        </div>
    )
}
```

- [ ] **Step 7: Verifikasi tipe & lint**

Run: `npx tsc --noEmit`
Expected: tidak ada error TypeScript baru terkait file yang dibuat/diubah pada task ini.

Run: `npx eslint "src/app/(protected-pages)/kategori-sparepart" src/services/kategoriSparepart.service.ts src/constants/api.constant.ts src/constants/route.constant.ts`
Expected: tidak ada error (warning boleh, ikuti konvensi file lain).

- [ ] **Step 8: Commit**

```bash
git add src/services/kategoriSparepart.service.ts src/constants/api.constant.ts src/constants/route.constant.ts \
        "src/app/(protected-pages)/kategori-sparepart"
git commit -m "feat: tambah halaman admin Kategori Sparepart"
```

---

## Task 6: Frontend — Paket Perawatan Sparepart (service + 3 halaman)

**Files:**
- Create: `src/services/paketPerawatanSparepart.service.ts`
- Modify: `src/constants/api.constant.ts`
- Modify: `src/constants/route.constant.ts`
- Create: `src/app/(protected-pages)/paket-perawatan-sparepart/page.tsx`
- Create: `src/app/(protected-pages)/paket-perawatan-sparepart/baru/page.tsx`
- Create: `src/app/(protected-pages)/paket-perawatan-sparepart/[id]/page.tsx`

**Interfaces:**
- Consumes: `GET/POST/PUT/DELETE /api/proxy/paket-perawatan-sparepart[/{id}]` dan `GET /api/proxy/paket-perawatan-sparepart/resolusi` dari Task 3; `jenisPerawatanService`, `jenisKendaraanService`, `sparepartService` (sudah ada) untuk dropdown.
- Produces: `paketPerawatanSparepartService.resolusi(...)` — dipakai Task 8 (`PerawatanForm.tsx`).

- [ ] **Step 1: Tambah entri di `api.constant.ts`**

Tambahkan setelah blok `// Kategori Sparepart` yang ditambahkan Task 5:

```ts
    // Paket Perawatan Sparepart
    PAKET_PERAWATAN_SPAREPART:          '/api/proxy/paket-perawatan-sparepart',
    PAKET_PERAWATAN_SPAREPART_DETAIL:   (id: string) => `/api/proxy/paket-perawatan-sparepart/${id}`,
    PAKET_PERAWATAN_SPAREPART_RESOLUSI: '/api/proxy/paket-perawatan-sparepart/resolusi',
```

- [ ] **Step 2: Tambah entri di `route.constant.ts`**

Tambahkan setelah baris `KATEGORI_SPAREPART_DETAIL` yang ditambahkan Task 5:

```ts
    PAKET_PERAWATAN_SPAREPART:        '/paket-perawatan-sparepart',
    PAKET_PERAWATAN_SPAREPART_BARU:   '/paket-perawatan-sparepart/baru',
    PAKET_PERAWATAN_SPAREPART_DETAIL: (id: string) => `/paket-perawatan-sparepart/${id}`,
```

- [ ] **Step 3: Service**

```ts
// src/services/paketPerawatanSparepart.service.ts
import axios from 'axios'
import { API_ENDPOINTS } from '@/constants/api.constant'

export interface PaketPerawatanSparepart {
    id_paket_perawatan_sparepart: string
    id_perusahaan: string
    id_jenis_perawatan: string
    id_jenis_kendaraan: string
    id_sparepart: string
    nama_jenis_perawatan: string | null
    nama_jenis_kendaraan: string | null
    nama_sparepart: string | null
    satuan_sparepart: string | null
    qty_standar: number
    aktif: boolean
    dibuat_pada: string
    diubah_pada: string | null
}

export type PaketPerawatanSparepartPayload = {
    id_jenis_perawatan: string
    id_jenis_kendaraan: string
    id_sparepart: string
    qty_standar: number
    aktif?: boolean
}

export interface PaketResolusiItem {
    id_sparepart: string
    nama_sparepart: string
    satuan_sparepart: string
    qty_standar: number
    harga_standar: number
}

export const paketPerawatanSparepartService = {
    async list(params?: { page?: number; limit?: number; id_jenis_perawatan?: string; id_jenis_kendaraan?: string }) {
        const { data } = await axios.get(API_ENDPOINTS.PAKET_PERAWATAN_SPAREPART, { params })
        return data as { data: PaketPerawatanSparepart[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
    async get(id: string) {
        const { data } = await axios.get(API_ENDPOINTS.PAKET_PERAWATAN_SPAREPART_DETAIL(id))
        return data.data as PaketPerawatanSparepart
    },
    async create(payload: PaketPerawatanSparepartPayload) {
        const { data } = await axios.post(API_ENDPOINTS.PAKET_PERAWATAN_SPAREPART, payload)
        return data.data as PaketPerawatanSparepart
    },
    async update(id: string, payload: Partial<PaketPerawatanSparepartPayload>) {
        const { data } = await axios.put(API_ENDPOINTS.PAKET_PERAWATAN_SPAREPART_DETAIL(id), payload)
        return data.data as PaketPerawatanSparepart
    },
    async delete(id: string) {
        await axios.delete(API_ENDPOINTS.PAKET_PERAWATAN_SPAREPART_DETAIL(id))
    },
    async resolusi(params: { id_jenis_perawatan: string; id_jenis_kendaraan: string }): Promise<PaketResolusiItem[]> {
        const { data } = await axios.get(API_ENDPOINTS.PAKET_PERAWATAN_SPAREPART_RESOLUSI, { params })
        return (data?.data ?? []) as PaketResolusiItem[]
    },
}
```

- [ ] **Step 4: Halaman list**

```tsx
// src/app/(protected-pages)/paket-perawatan-sparepart/page.tsx
'use client'
import { useEffect, useState, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, Input, Tooltip, toast, Notification } from '@/components/ui'
import ConfirmDialog from '@/components/shared/ConfirmDialog'
import DataTable from '@/components/shared/DataTable'
import type { ColumnDef, CellContext } from '@/components/shared/DataTable'
import { HiOutlineSearch, HiOutlinePencilAlt, HiOutlineTrash, HiPlusCircle } from 'react-icons/hi'
import { paketPerawatanSparepartService, PaketPerawatanSparepart } from '@/services/paketPerawatanSparepart.service'
import { ROUTES } from '@/constants/route.constant'
import { parseApiError } from '@/utils/error.util'
import { formatNum } from '@/utils/formatNumber'

export default function PaketPerawatanSparepartPage() {
    const router = useRouter()
    const [list, setList] = useState<PaketPerawatanSparepart[]>([])
    const [loading, setLoading] = useState(true)
    const [search, setSearch] = useState('')
    const [currentPage, setCurrentPage] = useState(1)
    const [total, setTotal] = useState(0)
    const [pageSize, setPageSize] = useState(10)

    const [deleteTarget, setDeleteTarget] = useState<PaketPerawatanSparepart | null>(null)
    const [submitting, setSubmitting] = useState(false)

    const fetchData = useCallback(async () => {
        setLoading(true)
        try {
            const res = await paketPerawatanSparepartService.list({ page: currentPage, limit: pageSize })
            setList(res.data)
            setTotal(res.meta.total)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [currentPage, pageSize])

    useEffect(() => { fetchData() }, [fetchData])

    const filteredList = list.filter(l => {
        if (!search) return true
        const q = search.toLowerCase()
        return (l.nama_jenis_perawatan ?? '').toLowerCase().includes(q)
            || (l.nama_jenis_kendaraan ?? '').toLowerCase().includes(q)
            || (l.nama_sparepart ?? '').toLowerCase().includes(q)
    })

    const handleDelete = async () => {
        if (!deleteTarget) return
        setSubmitting(true)
        try {
            await paketPerawatanSparepartService.delete(deleteTarget.id_paket_perawatan_sparepart)
            toast.push(<Notification type="success" title="Paket sparepart berhasil dihapus" />)
            setDeleteTarget(null)
            fetchData()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSubmitting(false)
        }
    }

    const columns: ColumnDef<PaketPerawatanSparepart>[] = [
        { header: 'No', id: 'no', size: 60,
            cell: (props: CellContext<PaketPerawatanSparepart, unknown>) => (currentPage - 1) * pageSize + props.row.index + 1 },
        { header: 'Jenis Perawatan', accessorKey: 'nama_jenis_perawatan',
            cell: (props: CellContext<PaketPerawatanSparepart, unknown>) => props.row.original.nama_jenis_perawatan ?? '—' },
        { header: 'Jenis Kendaraan', accessorKey: 'nama_jenis_kendaraan',
            cell: (props: CellContext<PaketPerawatanSparepart, unknown>) => props.row.original.nama_jenis_kendaraan ?? '—' },
        { header: 'Sparepart', accessorKey: 'nama_sparepart',
            cell: (props: CellContext<PaketPerawatanSparepart, unknown>) => props.row.original.nama_sparepart ?? '—' },
        { header: 'Qty Standar', accessorKey: 'qty_standar',
            cell: (props: CellContext<PaketPerawatanSparepart, unknown>) =>
                `${formatNum(props.row.original.qty_standar)} ${props.row.original.satuan_sparepart ?? ''}` },
        { header: '', accessorKey: 'id_paket_perawatan_sparepart',
            cell: (props: CellContext<PaketPerawatanSparepart, unknown>) => {
                const row = props.row.original
                return (
                    <div className="flex items-center justify-end gap-1">
                        <Tooltip title="Edit">
                            <span className="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 hover:bg-blue-200 cursor-pointer transition-colors"
                                onClick={() => router.push(ROUTES.PAKET_PERAWATAN_SPAREPART_DETAIL(row.id_paket_perawatan_sparepart))}>
                                <HiOutlinePencilAlt className="text-base" />
                            </span>
                        </Tooltip>
                        <Tooltip title="Hapus">
                            <span className="flex items-center justify-center w-8 h-8 rounded-lg bg-red-100 dark:bg-red-500/20 text-red-500 dark:text-red-400 hover:bg-red-200 cursor-pointer transition-colors"
                                onClick={() => setDeleteTarget(row)}>
                                <HiOutlineTrash className="text-base" />
                            </span>
                        </Tooltip>
                    </div>
                )
            },
        },
    ]

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="font-bold">Paket Sparepart</h3>
                    <p className="text-gray-500 text-sm mt-0.5">Daftar part standar per jenis perawatan &amp; jenis kendaraan — dasar auto-fill form Catat Perawatan</p>
                </div>
                <Button variant="solid" size="sm" icon={<HiPlusCircle />} onClick={() => router.push(ROUTES.PAKET_PERAWATAN_SPAREPART_BARU)}>
                    Tambah Paket
                </Button>
            </div>
            <Card bodyClass="p-0">
                <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 p-4 border-b border-gray-100 dark:border-gray-700">
                    <div className="flex-1">
                        <Input placeholder="Cari jenis perawatan, jenis kendaraan, atau sparepart..."
                            suffix={<HiOutlineSearch className="text-gray-400" />}
                            value={search} onChange={e => setSearch(e.target.value)} />
                    </div>
                </div>
                <DataTable columns={columns as ColumnDef<unknown>[]} data={filteredList as unknown[]} loading={loading}
                    noData={!loading && filteredList.length === 0}
                    pagingData={{ total, pageIndex: currentPage, pageSize }}
                    onPaginationChange={setCurrentPage}
                    onSort={() => {}}
                    onSelectChange={size => { setPageSize(size); setCurrentPage(1) }}
                    selectable={false} />
            </Card>
            <ConfirmDialog isOpen={!!deleteTarget} type="danger" title="Hapus Paket Sparepart?"
                confirmText="Ya, Hapus" cancelText="Batal"
                confirmButtonProps={{ loading: submitting }}
                onClose={() => setDeleteTarget(null)} onCancel={() => setDeleteTarget(null)} onConfirm={handleDelete}>
                <p className="text-sm">
                    Paket <span className="font-semibold">&ldquo;{deleteTarget?.nama_sparepart}&rdquo;</span> untuk {deleteTarget?.nama_jenis_perawatan} ({deleteTarget?.nama_jenis_kendaraan}) akan dihapus. Form Catat Perawatan tidak akan auto-fill part ini lagi. Lanjutkan?
                </p>
            </ConfirmDialog>
        </div>
    )
}
```

- [ ] **Step 5: Halaman Tambah**

```tsx
// src/app/(protected-pages)/paket-perawatan-sparepart/baru/page.tsx
'use client'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, FormItem, Input, toast, Notification } from '@/components/ui'
import Select from '@/components/ui/Select'
import { HiArrowLeft } from 'react-icons/hi'
import { paketPerawatanSparepartService } from '@/services/paketPerawatanSparepart.service'
import { jenisPerawatanService } from '@/services/jenisPerawatan.service'
import { jenisKendaraanService, JenisKendaraan } from '@/services/jenis-kendaraan.service'
import { sparepartService, Sparepart } from '@/services/sparepart.service'
import { ROUTES } from '@/constants/route.constant'
import { parseApiError } from '@/utils/error.util'

interface FormState {
    id_jenis_perawatan: string
    id_jenis_kendaraan: string
    id_sparepart: string
    qty_standar: string
}

type Option = { value: string; label: string }

const INIT: FormState = { id_jenis_perawatan: '', id_jenis_kendaraan: '', id_sparepart: '', qty_standar: '' }

export default function PaketPerawatanSparepartBaruPage() {
    const router = useRouter()
    const [form, setForm] = useState<FormState>(INIT)
    const [saving, setSaving] = useState(false)
    const [jenisPerawatanOptions, setJenisPerawatanOptions] = useState<Option[]>([])
    const [jenisKendaraanOptions, setJenisKendaraanOptions] = useState<Option[]>([])
    const [sparepartOptions, setSparepartOptions] = useState<Option[]>([])
    const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({})

    useEffect(() => {
        jenisPerawatanService.list(1, 100)
            .then(res => setJenisPerawatanOptions(res.data.filter(j => j.aktif).map(j => ({ value: j.id_jenis_perawatan, label: j.nama }))))
            .catch(() => {})
        jenisKendaraanService.list(1, 100)
            .then(res => setJenisKendaraanOptions(res.data.map((j: JenisKendaraan) => ({ value: j.id_jenis_kendaraan, label: j.nama_jenis }))))
            .catch(() => {})
        sparepartService.list({ page: 1, limit: 100 })
            .then(res => setSparepartOptions(res.data.filter((s: Sparepart) => s.aktif).map((s: Sparepart) => ({ value: s.id_sparepart, label: `${s.nama} (${s.satuan})` }))))
            .catch(() => {})
    }, [])

    const set = (field: keyof FormState, value: string) => setForm(p => ({ ...p, [field]: value }))

    const validate = () => {
        const e: Partial<Record<keyof FormState, string>> = {}
        if (!form.id_jenis_perawatan) e.id_jenis_perawatan = 'Jenis perawatan wajib diisi'
        if (!form.id_jenis_kendaraan) e.id_jenis_kendaraan = 'Jenis kendaraan wajib diisi'
        if (!form.id_sparepart) e.id_sparepart = 'Sparepart wajib diisi'
        if (!form.qty_standar || parseInt(form.qty_standar) <= 0) e.qty_standar = 'Qty standar wajib diisi'
        setErrors(e)
        return Object.keys(e).length === 0
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        if (!validate()) {
            toast.push(<Notification type="danger" title="Periksa kembali data yang belum lengkap" />)
            window.scrollTo({ top: 0, behavior: 'smooth' })
            return
        }
        setSaving(true)
        try {
            await paketPerawatanSparepartService.create({
                id_jenis_perawatan: form.id_jenis_perawatan,
                id_jenis_kendaraan: form.id_jenis_kendaraan,
                id_sparepart: form.id_sparepart,
                qty_standar: parseInt(form.qty_standar),
            })
            toast.push(<Notification type="success" title="Paket sparepart berhasil ditambahkan" />)
            router.push(ROUTES.PAKET_PERAWATAN_SPAREPART)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <button type="button" onClick={() => router.push(ROUTES.PAKET_PERAWATAN_SPAREPART)}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors">
                    <HiArrowLeft className="text-xl" />
                </button>
                <div>
                    <h4 className="font-bold">Tambah Paket Sparepart</h4>
                    <p className="text-sm text-gray-500 mt-0.5">Satu baris part per kombinasi jenis perawatan &amp; jenis kendaraan</p>
                </div>
            </div>
            <Card>
                <form onSubmit={handleSubmit}>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                        <FormItem label="Jenis Perawatan" asterisk invalid={!!errors.id_jenis_perawatan} errorMessage={errors.id_jenis_perawatan}>
                            <Select<Option> isSearchable placeholder="Pilih jenis perawatan..."
                                options={jenisPerawatanOptions}
                                value={jenisPerawatanOptions.find(o => o.value === form.id_jenis_perawatan) ?? null}
                                onChange={opt => set('id_jenis_perawatan', opt?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Jenis Kendaraan" asterisk invalid={!!errors.id_jenis_kendaraan} errorMessage={errors.id_jenis_kendaraan}>
                            <Select<Option> isSearchable placeholder="Pilih jenis kendaraan..."
                                options={jenisKendaraanOptions}
                                value={jenisKendaraanOptions.find(o => o.value === form.id_jenis_kendaraan) ?? null}
                                onChange={opt => set('id_jenis_kendaraan', opt?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Sparepart" asterisk invalid={!!errors.id_sparepart} errorMessage={errors.id_sparepart}>
                            <Select<Option> isSearchable placeholder="Pilih sparepart..."
                                options={sparepartOptions}
                                value={sparepartOptions.find(o => o.value === form.id_sparepart) ?? null}
                                onChange={opt => set('id_sparepart', opt?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Qty Standar" asterisk invalid={!!errors.qty_standar} errorMessage={errors.qty_standar}>
                            <Input type="number" step="1" min="1" placeholder="Contoh: 6"
                                value={form.qty_standar}
                                invalid={!!errors.qty_standar}
                                onChange={e => set('qty_standar', e.target.value.replace(/\D/g, ''))} />
                        </FormItem>
                    </div>
                    <div className="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <Button type="button" variant="plain" onClick={() => router.push(ROUTES.PAKET_PERAWATAN_SPAREPART)}>Batal</Button>
                        <Button type="submit" variant="solid" loading={saving}>Simpan Paket</Button>
                    </div>
                </form>
            </Card>
        </div>
    )
}
```

- [ ] **Step 6: Halaman Detail/Edit**

```tsx
// src/app/(protected-pages)/paket-perawatan-sparepart/[id]/page.tsx
'use client'
import { use, useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Card, Button, FormItem, Input, toast, Notification } from '@/components/ui'
import Select from '@/components/ui/Select'
import { HiArrowLeft } from 'react-icons/hi'
import { paketPerawatanSparepartService } from '@/services/paketPerawatanSparepart.service'
import { jenisPerawatanService } from '@/services/jenisPerawatan.service'
import { jenisKendaraanService, JenisKendaraan } from '@/services/jenis-kendaraan.service'
import { sparepartService, Sparepart } from '@/services/sparepart.service'
import { ROUTES } from '@/constants/route.constant'
import { parseApiError } from '@/utils/error.util'

interface FormState {
    id_jenis_perawatan: string
    id_jenis_kendaraan: string
    id_sparepart: string
    qty_standar: string
}

type Option = { value: string; label: string }

const INIT: FormState = { id_jenis_perawatan: '', id_jenis_kendaraan: '', id_sparepart: '', qty_standar: '' }

export default function PaketPerawatanSparepartDetailPage({ params }: { params: Promise<{ id: string }> }) {
    const { id } = use(params)
    const router = useRouter()
    const [form, setForm] = useState<FormState>(INIT)
    const [saving, setSaving] = useState(false)
    const [jenisPerawatanOptions, setJenisPerawatanOptions] = useState<Option[]>([])
    const [jenisKendaraanOptions, setJenisKendaraanOptions] = useState<Option[]>([])
    const [sparepartOptions, setSparepartOptions] = useState<Option[]>([])
    const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({})
    const [loading, setLoading] = useState(true)
    const [notFound, setNotFound] = useState(false)

    useEffect(() => {
        jenisPerawatanService.list(1, 100)
            .then(res => setJenisPerawatanOptions(res.data.filter(j => j.aktif).map(j => ({ value: j.id_jenis_perawatan, label: j.nama }))))
            .catch(() => {})
        jenisKendaraanService.list(1, 100)
            .then(res => setJenisKendaraanOptions(res.data.map((j: JenisKendaraan) => ({ value: j.id_jenis_kendaraan, label: j.nama_jenis }))))
            .catch(() => {})
        sparepartService.list({ page: 1, limit: 100 })
            .then(res => setSparepartOptions(res.data.filter((s: Sparepart) => s.aktif).map((s: Sparepart) => ({ value: s.id_sparepart, label: `${s.nama} (${s.satuan})` }))))
            .catch(() => {})
    }, [])

    useEffect(() => {
        paketPerawatanSparepartService.get(id)
            .then(d => setForm({
                id_jenis_perawatan: d.id_jenis_perawatan,
                id_jenis_kendaraan: d.id_jenis_kendaraan,
                id_sparepart: d.id_sparepart,
                qty_standar: String(d.qty_standar),
            }))
            .catch(() => setNotFound(true))
            .finally(() => setLoading(false))
    }, [id])

    const set = (field: keyof FormState, value: string) => setForm(p => ({ ...p, [field]: value }))

    const validate = () => {
        const e: Partial<Record<keyof FormState, string>> = {}
        if (!form.id_jenis_perawatan) e.id_jenis_perawatan = 'Jenis perawatan wajib diisi'
        if (!form.id_jenis_kendaraan) e.id_jenis_kendaraan = 'Jenis kendaraan wajib diisi'
        if (!form.id_sparepart) e.id_sparepart = 'Sparepart wajib diisi'
        if (!form.qty_standar || parseInt(form.qty_standar) <= 0) e.qty_standar = 'Qty standar wajib diisi'
        setErrors(e)
        return Object.keys(e).length === 0
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        if (!validate()) {
            toast.push(<Notification type="danger" title="Periksa kembali data yang belum lengkap" />)
            window.scrollTo({ top: 0, behavior: 'smooth' })
            return
        }
        setSaving(true)
        try {
            await paketPerawatanSparepartService.update(id, {
                id_jenis_perawatan: form.id_jenis_perawatan,
                id_jenis_kendaraan: form.id_jenis_kendaraan,
                id_sparepart: form.id_sparepart,
                qty_standar: parseInt(form.qty_standar),
            })
            toast.push(<Notification type="success" title="Paket sparepart berhasil diperbarui" />)
            router.push(ROUTES.PAKET_PERAWATAN_SPAREPART)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    if (loading) return <div className="p-6 text-gray-500">Memuat...</div>
    if (notFound) return <div className="p-6 text-red-500">Paket sparepart tidak ditemukan.</div>

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <button type="button" onClick={() => router.push(ROUTES.PAKET_PERAWATAN_SPAREPART)}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors">
                    <HiArrowLeft className="text-xl" />
                </button>
                <div>
                    <h4 className="font-bold">Ubah Paket Sparepart</h4>
                    <p className="text-sm text-gray-500 mt-0.5">Satu baris part per kombinasi jenis perawatan &amp; jenis kendaraan</p>
                </div>
            </div>
            <Card>
                <form onSubmit={handleSubmit}>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                        <FormItem label="Jenis Perawatan" asterisk invalid={!!errors.id_jenis_perawatan} errorMessage={errors.id_jenis_perawatan}>
                            <Select<Option> isSearchable placeholder="Pilih jenis perawatan..."
                                options={jenisPerawatanOptions}
                                value={jenisPerawatanOptions.find(o => o.value === form.id_jenis_perawatan) ?? null}
                                onChange={opt => set('id_jenis_perawatan', opt?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Jenis Kendaraan" asterisk invalid={!!errors.id_jenis_kendaraan} errorMessage={errors.id_jenis_kendaraan}>
                            <Select<Option> isSearchable placeholder="Pilih jenis kendaraan..."
                                options={jenisKendaraanOptions}
                                value={jenisKendaraanOptions.find(o => o.value === form.id_jenis_kendaraan) ?? null}
                                onChange={opt => set('id_jenis_kendaraan', opt?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Sparepart" asterisk invalid={!!errors.id_sparepart} errorMessage={errors.id_sparepart}>
                            <Select<Option> isSearchable placeholder="Pilih sparepart..."
                                options={sparepartOptions}
                                value={sparepartOptions.find(o => o.value === form.id_sparepart) ?? null}
                                onChange={opt => set('id_sparepart', opt?.value ?? '')} />
                        </FormItem>
                        <FormItem label="Qty Standar" asterisk invalid={!!errors.qty_standar} errorMessage={errors.qty_standar}>
                            <Input type="number" step="1" min="1" placeholder="Contoh: 6"
                                value={form.qty_standar}
                                invalid={!!errors.qty_standar}
                                onChange={e => set('qty_standar', e.target.value.replace(/\D/g, ''))} />
                        </FormItem>
                    </div>
                    <div className="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <Button type="button" variant="plain" onClick={() => router.push(ROUTES.PAKET_PERAWATAN_SPAREPART)}>Batal</Button>
                        <Button type="submit" variant="solid" loading={saving}>Simpan Perubahan</Button>
                    </div>
                </form>
            </Card>
        </div>
    )
}
```

- [ ] **Step 7: Verifikasi tipe & lint**

Run: `npx tsc --noEmit`
Expected: tidak ada error TypeScript baru terkait file yang dibuat/diubah pada task ini.

Run: `npx eslint "src/app/(protected-pages)/paket-perawatan-sparepart" src/services/paketPerawatanSparepart.service.ts`
Expected: tidak ada error.

- [ ] **Step 8: Commit**

```bash
git add src/services/paketPerawatanSparepart.service.ts src/constants/api.constant.ts src/constants/route.constant.ts \
        "src/app/(protected-pages)/paket-perawatan-sparepart"
git commit -m "feat: tambah halaman admin Paket Perawatan Sparepart"
```

---

## Task 7: Frontend — integrasikan Kategori ke halaman Sparepart

**Files:**
- Modify: `src/services/sparepart.service.ts`
- Modify: `src/app/(protected-pages)/sparepart/page.tsx`
- Modify: `src/app/(protected-pages)/sparepart/baru/page.tsx`
- Modify: `src/app/(protected-pages)/sparepart/[id]/page.tsx`

**Interfaces:**
- Consumes: `kategoriSparepartService` dari Task 5; `id_kategori_sparepart`/`nama_kategori_sparepart` di response `sparepartService` dari Task 2 (backend).

- [ ] **Step 1: Update tipe & payload di `sparepart.service.ts`**

Edit `src/services/sparepart.service.ts` — tambahkan field ke `Sparepart` interface (setelah `nama: string`):

```ts
    id_kategori_sparepart: string | null
    nama_kategori_sparepart: string | null
```

Tambahkan field opsional ke `SparepartPayload` (setelah `nama: string`):

```ts
    id_kategori_sparepart?: string | null
```

Tambahkan parameter filter opsional ke method `list`:

```ts
    async list(params?: { page?: number; limit?: number; search?: string; id_kategori_sparepart?: string }) {
        const { data } = await axios.get(API_ENDPOINTS.SPAREPART, { params })
        return data as { data: Sparepart[]; meta: { page: number; total: number; totalPages: number; limit: number } }
    },
```

- [ ] **Step 2: Tambah kolom & filter Kategori di halaman list**

Edit `src/app/(protected-pages)/sparepart/page.tsx`:

Tambahkan import di bagian atas file:

```ts
import Select from '@/components/ui/Select'
import { kategoriSparepartService, KategoriSparepart } from '@/services/kategoriSparepart.service'
```

Tambahkan state baru setelah deklarasi `const [deleteTarget, ...]`:

```ts
    const [kategoriOptions, setKategoriOptions] = useState<{ value: string; label: string }[]>([])
    const [kategoriFilter, setKategoriFilter] = useState('')
```

Tambahkan `useEffect` baru setelah deklarasi `fetchData` (sebelum `useEffect(() => { fetchData() }, [fetchData])`):

```ts
    useEffect(() => {
        kategoriSparepartService.list(1, 100)
            .then(res => setKategoriOptions(res.data.map((k: KategoriSparepart) => ({ value: k.id_kategori_sparepart, label: k.nama }))))
            .catch(() => {})
    }, [])
```

Ganti isi `fetchData` agar meneruskan filter kategori:

```ts
    const fetchData = useCallback(async () => {
        setLoading(true)
        try {
            const res = await sparepartService.list({ page: currentPage, limit: pageSize, search: search || undefined, id_kategori_sparepart: kategoriFilter || undefined })
            setList(res.data)
            setTotal(res.meta.total)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [currentPage, pageSize, search, kategoriFilter])
```

Tambahkan kolom "Kategori" ke array `columns`, setelah kolom `Nama`:

```ts
        { header: 'Kategori', accessorKey: 'nama_kategori_sparepart', size: 160,
            cell: ({ row }: CellContext<Sparepart, unknown>) => row.original.nama_kategori_sparepart
                ? <span>{row.original.nama_kategori_sparepart}</span>
                : <span className="text-gray-400">—</span>,
        },
```

Tambahkan dropdown filter kategori di toolbar pencarian (di dalam `<Card bodyClass="p-0">`, ubah `<div className="flex items-center gap-3 px-4 py-3">` menjadi menampung dropdown tambahan):

```tsx
                <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 px-4 py-3">
                    <Input className="flex-1" placeholder="Cari kode atau nama spare part... (tekan Enter)"
                        suffix={searchInput
                            ? <HiOutlineX className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchClear} />
                            : <HiOutlineSearch className="text-gray-400 text-lg cursor-pointer hover:text-gray-600" onClick={handleSearchSubmit} />}
                        value={searchInput}
                        onChange={e => setSearchInput(e.target.value)}
                        onKeyDown={e => { if (e.key === 'Enter') handleSearchSubmit() }} />
                    <div className="w-full sm:w-56">
                        <Select isClearable placeholder="Semua kategori"
                            options={kategoriOptions}
                            value={kategoriOptions.find(o => o.value === kategoriFilter) ?? null}
                            onChange={opt => { setKategoriFilter((opt as { value: string } | null)?.value ?? ''); setCurrentPage(1) }} />
                    </div>
                </div>
```

- [ ] **Step 3: Tambah dropdown Kategori di halaman Tambah Sparepart**

Edit `src/app/(protected-pages)/sparepart/baru/page.tsx`:

Tambahkan import:

```ts
import Select from '@/components/ui/Select'
import { kategoriSparepartService, KategoriSparepart } from '@/services/kategoriSparepart.service'
```

Ubah state form untuk menyertakan `id_kategori_sparepart`:

```ts
    const [form, setForm] = useState({ kode: '', nama: '', id_kategori_sparepart: '', satuan: 'pcs', harga_standar: '' })
    const [kategoriOptions, setKategoriOptions] = useState<{ value: string; label: string }[]>([])

    useEffect(() => {
        kategoriSparepartService.list(1, 100)
            .then(res => setKategoriOptions(res.data.filter(k => k.aktif).map((k: KategoriSparepart) => ({ value: k.id_kategori_sparepart, label: k.nama }))))
            .catch(() => {})
    }, [])
```

(tambahkan `import { useEffect, useState } from 'react'` jika belum ada `useEffect` di import React yang sudah ada — file ini sebelumnya hanya `import { useState } from 'react'`.)

Ubah payload `create` di `handleSubmit`:

```ts
            await sparepartService.create({
                kode: form.kode,
                nama: form.nama,
                id_kategori_sparepart: form.id_kategori_sparepart || null,
                satuan: form.satuan || 'pcs',
                harga_standar: form.harga_standar ? Number(form.harga_standar) : 0,
            })
```

Tambahkan `FormItem` dropdown Kategori setelah `FormItem` "Nama":

```tsx
                    <FormItem label="Kategori">
                        <Select isSearchable isClearable placeholder="Pilih kategori (opsional)..."
                            options={kategoriOptions}
                            value={kategoriOptions.find(o => o.value === form.id_kategori_sparepart) ?? null}
                            onChange={opt => setForm(p => ({ ...p, id_kategori_sparepart: (opt as { value: string } | null)?.value ?? '' }))} />
                    </FormItem>
```

- [ ] **Step 4: Tambah dropdown Kategori di halaman Detail/Edit Sparepart**

Edit `src/app/(protected-pages)/sparepart/[id]/page.tsx`:

Tambahkan import:

```ts
import { kategoriSparepartService, KategoriSparepart } from '@/services/kategoriSparepart.service'
```

Ubah state `form` untuk menyertakan `id_kategori_sparepart`, tambahkan state opsi kategori:

```ts
    const [form, setForm] = useState({ kode: '', nama: '', id_kategori_sparepart: '', satuan: '', harga_standar: '', aktif: true })
    const [kategoriOptions, setKategoriOptions] = useState<{ value: string; label: string }[]>([])

    useEffect(() => {
        kategoriSparepartService.list(1, 100)
            .then(res => setKategoriOptions(res.data.filter(k => k.aktif).map((k: KategoriSparepart) => ({ value: k.id_kategori_sparepart, label: k.nama }))))
            .catch(() => {})
    }, [])
```

Ubah `fetchSparepart` agar mengisi `id_kategori_sparepart` ke form:

```ts
    const fetchSparepart = useCallback(async () => {
        try {
            const sp = await sparepartService.get(id)
            setSparepart(sp)
            setForm({ kode: sp.kode, nama: sp.nama, id_kategori_sparepart: sp.id_kategori_sparepart ?? '', satuan: sp.satuan, harga_standar: String(sp.harga_standar), aktif: sp.aktif })
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [id])
```

Ubah payload `handleSave`:

```ts
            const updated = await sparepartService.update(id, {
                kode: form.kode,
                nama: form.nama,
                id_kategori_sparepart: form.id_kategori_sparepart || null,
                satuan: form.satuan || 'pcs',
                harga_standar: Number(form.harga_standar) || 0,
                aktif: form.aktif,
            })
```

Tambahkan baris "Kategori" ke tampilan info non-edit (array literal `{label, value}` di bagian `!editing`), setelah baris "Nama":

```ts
                            { label: 'Kategori', value: sparepart.nama_kategori_sparepart ?? <span className="text-gray-400">—</span> },
```

Tambahkan `FormItem` dropdown Kategori di form edit, setelah `FormItem` "Nama":

```tsx
                            <FormItem label="Kategori">
                                <Select isSearchable isClearable placeholder="Pilih kategori (opsional)..."
                                    options={kategoriOptions}
                                    value={kategoriOptions.find(o => o.value === form.id_kategori_sparepart) ?? null}
                                    onChange={opt => setForm(p => ({ ...p, id_kategori_sparepart: (opt as { value: string } | null)?.value ?? '' }))} />
                            </FormItem>
```

- [ ] **Step 5: Verifikasi tipe & lint**

Run: `npx tsc --noEmit`
Expected: tidak ada error TypeScript baru pada ketiga file yang diubah.

Run: `npx eslint "src/app/(protected-pages)/sparepart" src/services/sparepart.service.ts`
Expected: tidak ada error.

- [ ] **Step 6: Commit**

```bash
git add src/services/sparepart.service.ts "src/app/(protected-pages)/sparepart"
git commit -m "feat: tampilkan dan kelola kategori di halaman Sparepart"
```

---

## Task 8: Auto-fill daftar sparepart di form Catat Perawatan

**Files:**
- Modify: `src/app/(protected-pages)/perawatan-armada/PerawatanForm.tsx`

**Interfaces:**
- Consumes: `paketPerawatanSparepartService.resolusi(...)` dari Task 6.

- [ ] **Step 1: Tambah import**

Edit `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/perawatan-armada/PerawatanForm.tsx`, tambahkan baris berikut setelah `import { intervalPerawatanService } from '@/services/intervalPerawatan.service'`:

```ts
import { paketPerawatanSparepartService } from '@/services/paketPerawatanSparepart.service'
```

- [ ] **Step 2: Tambah `useEffect` auto-fill**

Tambahkan `useEffect` baru tepat setelah blok auto-fill "Jadwal Servis Berikutnya" yang sudah ada (setelah baris `}, [isEdit, form.id_armada, form.id_jenis_perawatan, form.tanggal, armadaList])`):

```ts
    // Auto-fill daftar sparepart dari paket standar — hanya saat CREATE dan list masih kosong,
    // supaya tidak menimpa part yang sudah ditambah manual.
    useEffect(() => {
        if (isEdit || items.length > 0) return
        const armada = armadaList.find(a => a.id_armada === form.id_armada)
        if (!armada?.id_jenis_kendaraan || !form.id_jenis_perawatan) return

        let aktif = true
        paketPerawatanSparepartService.resolusi({
            id_jenis_perawatan: form.id_jenis_perawatan,
            id_jenis_kendaraan: armada.id_jenis_kendaraan,
        })
            .then(res => {
                if (aktif && res.length > 0) {
                    setItems(res.map(r => ({
                        id_sparepart: r.id_sparepart,
                        qty: String(r.qty_standar),
                        harga: String(r.harga_standar),
                    })))
                }
            })
            .catch(() => {})
        return () => { aktif = false }
    }, [isEdit, form.id_armada, form.id_jenis_perawatan, armadaList, items.length])
```

- [ ] **Step 3: Verifikasi tipe & lint**

Run: `npx tsc --noEmit`
Expected: tidak ada error TypeScript baru terkait `PerawatanForm.tsx`.

Run: `npx eslint "src/app/(protected-pages)/perawatan-armada"`
Expected: tidak ada error.

- [ ] **Step 4: Verifikasi manual di browser**

1. Pastikan Task 1-7 sudah diterapkan (migrasi dijalankan, seeder dijalankan) di environment dev.
2. Buka `/perawatan-armada/baru`, pilih Armada bertipe Fuso dan Jenis Perawatan "Ganti Oli Mesin & Filter Oli".
3. Verifikasi daftar Spare Part Diganti otomatis terisi: Oli Mesin Diesel 15W-40 (qty 12) + Filter Oli (qty 1), dengan harga terisi dari harga standar.
4. Ubah salah satu qty secara manual, tambahkan 1 part lagi — pastikan tetap bisa diedit bebas setelah auto-fill.
5. Pilih Jenis Perawatan "Rotasi & Cek Tekanan Ban" (tidak punya paket default) — pastikan daftar part tetap kosong, tidak error.
6. Buka mode Edit pada catatan servis yang sudah ada — pastikan auto-fill TIDAK jalan (data tersimpan tidak tertimpa).

- [ ] **Step 5: Commit**

```bash
git add "TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/perawatan-armada/PerawatanForm.tsx"
git commit -m "feat: auto-fill daftar sparepart dari paket standar di form Catat Perawatan"
```
