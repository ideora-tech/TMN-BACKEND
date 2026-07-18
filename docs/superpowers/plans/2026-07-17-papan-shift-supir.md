# Papan Jadwal Shift Supir Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Master data Shift + tabel jadwal_shift + papan shift ala pilot-watch SIMPANDA (baris = supir proyek, sel = card shift) menggantikan papan keberangkatan di menu Penugasan.

**Architecture:** Backend 2 modul QB baru (`Shift` mirror JenisPerawatan; `JadwalShift` dengan batch create + aturan 1-shift-per-supir-per-tanggal global) + seed menu master. Frontend: 3 halaman master `/shift` (mirror jenis-perawatan), `PapanShift.tsx` baru menggantikan `PapanJadwal.tsx` (dihapus) di mode "Papan Jadwal" halaman Penugasan.

**Spec:** `docs/superpowers/specs/2026-07-17-papan-shift-supir-design.md` (WAJIB dibaca reviewer & implementer untuk konteks aturan).

## Global Constraints

- **JANGAN commit ke git** (kedua repo). Stage `git add` path spesifik. User commit manual.
- Backend: `DB::table()` only, NO Eloquent model, NO `SELECT *`, `RecordHelper` stamps, scope `id_perusahaan`, `whereNull('dihapus_pada')`, rule `exists:` selalu scoped `,dihapus_pada,NULL`.
- Middleware modul baru: `['api','auth:sanctum']` tanpa `izin:` (konsisten master data).
- Aturan jadwal_shift: maks 1 shift per supir per tanggal GLOBAL lintas proyek (app-level, soft-delete aware); supir wajib punya penugasan internal pending/aktif di proyek; batch response `{sukses, gagal:[{id_supir, alasan}]}` HTTP 200.
- Frontend: `parseApiError`, `ROUTES.*`, `API_ENDPOINTS.*`, NO `toLocaleString('id-ID')`, UI Indonesia.
- UUID menu Shift verbatim: `m0000001-0000-4000-8000-000000000057`, induk Data Master `m0000001-0000-4000-8000-000000000050`.
- Test backend: `vendor/bin/phpunit` dari host. Frontend: `npx tsc --noEmit` + `npx eslint`.
- `penugasan/page.tsx` adalah file aktif user — edit surgical seminimal mungkin.

---

### Task 1: Backend — migrations + modul `Shift` + seed menu

**Files:**
- Create: `database/migrations/2026_07_17_000007_create_shift_table.php`
- Create: `database/migrations/2026_07_17_000008_create_jadwal_shift_table.php`
- Create: `database/migrations/2026_07_17_000009_seed_menu_shift.php`
- Create: `app/Modules/Shift/` (8 file: Contracts/ShiftRepositoryInterface, ShiftRepository, ShiftService, ShiftController, ShiftServiceProvider, Requests/Store+UpdateShiftRequest, Resources/ShiftResource)
- Modify: `bootstrap/providers.php` (1 baris alfabetis: `App\Modules\Shift\ShiftServiceProvider::class,` — file ini punya perubahan staged dari plan lain, JANGAN sentuh baris lain)
- Create: `tests/Feature/ShiftTest.php`

**Pola referensi modul:** mirror PERSIS `app/Modules/JenisPerawatan/` (baca dulu semuanya) dengan substitusi:

| JenisPerawatan | Shift |
|---|---|
| `jenis_perawatan` (tabel) | `shift` |
| `id_jenis_perawatan` | `id_shift` |
| field `nama`, `keterangan` | `nama`, `jam_mulai`, `jam_selesai` |
| pesan "Jenis perawatan..." | "Shift..." |
| route `jenis-perawatan` | `shift` |

**Interfaces (Produces):** `Route::apiResource('shift')` → CRUD `/api/v1/shift[/{id}]`. Resource: `{id_shift, id_perusahaan, nama, jam_mulai, jam_selesai, aktif(bool), dibuat_pada, diubah_pada}` — `jam_mulai`/`jam_selesai` string `HH:mm:ss` apa adanya dari DB. Dipakai Task 2 (join) & Task 3/4 (frontend).

- [ ] **Step 1: Migrations** — kode lengkap:

`2026_07_17_000007_create_shift_table.php`:

```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift', function (Blueprint $table) {
            $table->char('id_shift', 36)->primary();
            $table->char('id_perusahaan', 36);
            $table->string('nama', 100);
            $table->time('jam_mulai');
            $table->time('jam_selesai'); // < jam_mulai berarti berakhir hari berikutnya
            $table->tinyInteger('aktif')->default(1);
            MigrationHelper::auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift');
    }
};
```

`2026_07_17_000008_create_jadwal_shift_table.php`:

```php
<?php

declare(strict_types=1);

use App\Helpers\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_shift', function (Blueprint $table) {
            $table->char('id_jadwal_shift', 36)->primary();
            $table->char('id_proyek', 36);
            $table->char('id_shift', 36);
            $table->char('id_supir', 36);
            $table->date('tanggal');
            MigrationHelper::auditColumns($table);
            $table->index(['id_supir', 'tanggal']);
            $table->index(['id_proyek', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_shift');
    }
};
```

`2026_07_17_000009_seed_menu_shift.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $idMenuShift  = 'm0000001-0000-4000-8000-000000000057';
    private string $idDataMaster = 'm0000001-0000-4000-8000-000000000050';

    public function up(): void
    {
        $now = now();

        DB::table('menu')->upsert([
            [
                'id_menu' => $this->idMenuShift, 'nama_menu' => 'Shift', 'path' => '/shift',
                'icon' => 'calendar', 'id_menu_induk' => $this->idDataMaster, 'urutan' => 7,
                'aktif' => 1, 'dibuat_pada' => $now, 'dibuat_oleh' => null,
            ],
        ], ['id_menu'], ['nama_menu', 'path', 'icon', 'id_menu_induk', 'urutan', 'aktif']);

        DB::table('menu_peran')->insertOrIgnore([
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'DISPATCHER'],
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'ADMIN'],
            ['id_menu' => $this->idMenuShift, 'kode_peran' => 'SUPERADMIN'],
        ]);
    }

    public function down(): void
    {
        DB::table('menu_peran')->where('id_menu', $this->idMenuShift)->delete();
        DB::table('menu')->where('id_menu', $this->idMenuShift)->delete();
    }
};
```

- [ ] **Step 2: Modul Shift** — mirror JenisPerawatan per tabel substitusi. Detail yang beda dari mirror murni:
  - `COLUMNS`: `['id_shift','id_perusahaan','nama','jam_mulai','jam_selesai','aktif', ...6 audit]`.
  - `StoreShiftRequest::rules()`: `nama` required|string|max:100; `jam_mulai` required|date_format:H:i; `jam_selesai` required|date_format:H:i; `aktif` sometimes|boolean. (`H:i` — frontend kirim `HH:mm`.)
  - `UpdateShiftRequest`: semua `sometimes` (jam tetap `date_format:H:i` bila dikirim).
  - `ShiftResource`: field sesuai Interfaces di atas; `aktif` cast bool.
  - `paginateByPerusahaan` orderBy `jam_mulai` lalu `nama`.
- [ ] **Step 3: TDD** — tulis `tests/Feature/ShiftTest.php` DULU (4 test, mirror pola JenisPerawatanTest): create 201 (kirim jam `08:00`/`16:00`, assert response + DB), list scoped perusahaan, update+show (ganti jam_selesai `04:00` — lintas tengah malam boleh), delete soft + 404 after. Run → FAIL 404 → implement → PASS.
- [ ] **Step 4:** Full suite regresi hijau. Stage:

```bash
git add database/migrations/2026_07_17_000007_create_shift_table.php database/migrations/2026_07_17_000008_create_jadwal_shift_table.php database/migrations/2026_07_17_000009_seed_menu_shift.php app/Modules/Shift bootstrap/providers.php tests/Feature/ShiftTest.php
```

---

### Task 2: Backend — modul `JadwalShift` (list + batch create + update + delete, aturan lengkap)

**Files:**
- Create: `app/Modules/JadwalShift/Contracts/JadwalShiftRepositoryInterface.php`
- Create: `app/Modules/JadwalShift/JadwalShiftRepository.php`
- Create: `app/Modules/JadwalShift/JadwalShiftService.php`
- Create: `app/Modules/JadwalShift/JadwalShiftController.php`
- Create: `app/Modules/JadwalShift/JadwalShiftServiceProvider.php`
- Create: `app/Modules/JadwalShift/Requests/StoreJadwalShiftRequest.php`
- Create: `app/Modules/JadwalShift/Requests/UpdateJadwalShiftRequest.php`
- Create: `app/Modules/JadwalShift/Resources/JadwalShiftResource.php`
- Modify: `bootstrap/providers.php` (1 baris alfabetis)
- Create: `tests/Feature/JadwalShiftTest.php`

**Interfaces (Produces):** routes `GET /api/v1/jadwal-shift` (wajib `id_proyek`, opsional `dari`/`sampai` tanggal), `POST /api/v1/jadwal-shift`, `PUT /api/v1/jadwal-shift/{id}`, `DELETE /api/v1/jadwal-shift/{id}`. List row: `{id_jadwal_shift, id_proyek, id_shift, id_supir, tanggal, shift_nama, jam_mulai, jam_selesai}`. POST response: `ApiResponse::success({sukses: int, gagal: [{id_supir, alasan}]})`.

- [ ] **Step 1: Tulis test yang gagal** — `tests/Feature/JadwalShiftTest.php` kode lengkap:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Penugasan\PenugasanModel;
use App\Modules\Proyek\ProyekModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JadwalShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeProyek(?string $idPerusahaan = null): ProyekModel
    {
        return ProyekModel::create([
            'id_perusahaan' => $idPerusahaan ?? self::PERUSAHAAN_ID,
            'id_klien'      => (string) Str::uuid(),
            'kode_proyek'   => 'PRJ-' . Str::random(8),
            'nama_proyek'   => 'Proyek Shift Test',
        ]);
    }

    private function makeSupir(string $nama = 'Budi'): string
    {
        $id = (string) Str::uuid();
        DB::table('supir')->insert([
            'id_supir' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'no_sim' => 'SIM-' . Str::random(8),
            'status' => 'aktif', 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function makePenugasan(string $idProyek, string $idSupir): PenugasanModel
    {
        return PenugasanModel::create([
            'id_proyek' => $idProyek, 'id_supir' => $idSupir, 'status' => 'aktif',
        ]);
    }

    private function makeShift(string $nama = 'Pagi', string $mulai = '08:00:00', string $selesai = '16:00:00'): string
    {
        $id = (string) Str::uuid();
        DB::table('shift')->insert([
            'id_shift' => $id, 'id_perusahaan' => self::PERUSAHAAN_ID,
            'nama' => $nama, 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    public function test_batch_create_sukses_dan_list_join_shift(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $supirA = $this->makeSupir('Budi');
        $supirB = $this->makeSupir('Andi');
        $this->makePenugasan($proyek->id_proyek, $supirA);
        $this->makePenugasan($proyek->id_proyek, $supirB);
        $shift = $this->makeShift('Pagi');

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek,
            'id_shift'  => $shift,
            'tanggal'   => '2026-07-20',
            'supir'     => [$supirA, $supirB],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sukses', 2)
            ->assertJsonPath('data.gagal', []);

        $list = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyek->id_proyek}&dari=2026-07-01&sampai=2026-07-31");
        $list->assertStatus(200);
        $this->assertCount(2, $list->json('data'));
        $this->assertSame('Pagi', $list->json('data.0.shift_nama'));
        $this->assertSame('08:00:00', $list->json('data.0.jam_mulai'));
    }

    public function test_dobel_tanggal_ditolak_per_item_lintas_proyek(): void
    {
        $this->actingAsRole('ADMIN');
        $proyekA = $this->makeProyek();
        $proyekB = $this->makeProyek();
        $supir = $this->makeSupir('Budi');
        $this->makePenugasan($proyekA->id_proyek, $supir);
        $this->makePenugasan($proyekB->id_proyek, $supir);
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyekA->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);

        // proyek BERBEDA, tanggal sama → tetap ditolak (aturan global)
        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyekB->id_proyek, 'id_shift' => $shiftMalam,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertCount(1, $res->json('data.gagal'));
        $this->assertStringContainsString('sudah dijadwalkan', $res->json('data.gagal.0.alasan'));
        $this->assertSame(1, DB::table('jadwal_shift')->whereNull('dihapus_pada')->count());
    }

    public function test_supir_tanpa_penugasan_di_proyek_ditolak_per_item(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $supirLuar = $this->makeSupir('Orang Luar'); // tidak di-assign
        $shift = $this->makeShift();

        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-07-20', 'supir' => [$supirLuar],
        ]);

        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertStringContainsString('tidak ter-assign', $res->json('data.gagal.0.alasan'));
    }

    public function test_update_ganti_shift_dan_delete_membuka_tanggal_lagi(): void
    {
        $this->actingAsRole('ADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir();
        $this->makePenugasan($proyek->id_proyek, $supir);
        $shiftPagi  = $this->makeShift('Pagi');
        $shiftMalam = $this->makeShift('Malam', '20:00:00', '04:00:00');

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-07-21', 'supir' => [$supir],
        ]);
        $idJadwal = DB::table('jadwal_shift')->value('id_jadwal_shift');

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", ['id_shift' => $shiftMalam])
            ->assertStatus(200)
            ->assertJsonPath('data.shift_nama', 'Malam');

        $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}")->assertStatus(200);
        $this->assertSoftDeleted('jadwal_shift', ['id_jadwal_shift' => $idJadwal]);

        // tanggal terbuka lagi setelah delete
        $res = $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftPagi,
            'tanggal' => '2026-07-21', 'supir' => [$supir],
        ]);
        $res->assertJsonPath('data.sukses', 1);
    }

    public function test_list_scoped_ke_perusahaan_dan_wajib_id_proyek(): void
    {
        $this->actingAsRole('ADMIN');
        $this->getJson('/api/v1/jadwal-shift')->assertStatus(422);

        $idPerusahaanLain = (string) Str::uuid();
        DB::table('perusahaan')->insert(['id_perusahaan' => $idPerusahaanLain, 'nama' => 'Lain', 'dibuat_pada' => now()]);
        $proyekLain = $this->makeProyek($idPerusahaanLain);

        $res = $this->getJson("/api/v1/jadwal-shift?id_proyek={$proyekLain->id_proyek}");
        $res->assertStatus(404); // proyek bukan milik perusahaan user
    }
}
```

- [ ] **Step 2: Run → FAIL (404 route).**
- [ ] **Step 3: Implementasi** — kode lengkap:

`Contracts/JadwalShiftRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Contracts;

interface JadwalShiftRepositoryInterface
{
    public function listByProyek(string $idProyek, ?string $dari, ?string $sampai): array;
    public function findById(string $id): ?object;
    public function findAktifBySupirTanggal(string $idSupir, string $tanggal): ?object;
    public function supirPunyaPenugasan(string $idProyek, string $idSupir): bool;
    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool;
    public function create(array $data): object;
    public function updateShift(object $record, string $idShift): object;
    public function delete(object $record): void;
}
```

`JadwalShiftRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Facades\DB;

class JadwalShiftRepository implements JadwalShiftRepositoryInterface
{
    private const COLUMNS = [
        'jadwal_shift.id_jadwal_shift', 'jadwal_shift.id_proyek', 'jadwal_shift.id_shift',
        'jadwal_shift.id_supir', 'jadwal_shift.tanggal',
    ];

    private const JOINED = [
        'shift.nama as shift_nama', 'shift.jam_mulai', 'shift.jam_selesai',
    ];

    public function listByProyek(string $idProyek, ?string $dari, ?string $sampai): array
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_proyek', $idProyek)
            ->when($dari, fn ($q, $v) => $q->where('jadwal_shift.tanggal', '>=', $v))
            ->when($sampai, fn ($q, $v) => $q->where('jadwal_shift.tanggal', '<=', $v))
            ->orderBy('jadwal_shift.tanggal')
            ->select(array_merge(self::COLUMNS, self::JOINED))
            ->get()
            ->all();
    }

    public function findById(string $id): ?object
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_jadwal_shift', $id)
            ->select(array_merge(self::COLUMNS, self::JOINED))
            ->first();
    }

    public function findAktifBySupirTanggal(string $idSupir, string $tanggal): ?object
    {
        return DB::table('jadwal_shift')
            ->join('shift', 'shift.id_shift', '=', 'jadwal_shift.id_shift')
            ->join('proyek', 'proyek.id_proyek', '=', 'jadwal_shift.id_proyek')
            ->whereNull('jadwal_shift.dihapus_pada')
            ->where('jadwal_shift.id_supir', $idSupir)
            ->where('jadwal_shift.tanggal', $tanggal)
            ->select(array_merge(self::COLUMNS, ['shift.nama as shift_nama', 'proyek.nama_proyek']))
            ->first();
    }

    public function supirPunyaPenugasan(string $idProyek, string $idSupir): bool
    {
        return DB::table('penugasan')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_supir', $idSupir)
            ->whereIn('status', ['pending', 'aktif'])
            ->exists();
    }

    public function proyekMilikPerusahaan(string $idProyek, string $idPerusahaan): bool
    {
        return DB::table('proyek')
            ->whereNull('dihapus_pada')
            ->where('id_proyek', $idProyek)
            ->where('id_perusahaan', $idPerusahaan)
            ->exists();
    }

    public function create(array $data): object
    {
        $data = RecordHelper::stampCreate($data, 'id_jadwal_shift');
        DB::table('jadwal_shift')->insert($data);
        return $this->findById($data['id_jadwal_shift']);
    }

    public function updateShift(object $record, string $idShift): object
    {
        DB::table('jadwal_shift')
            ->where('id_jadwal_shift', $record->id_jadwal_shift)
            ->update(RecordHelper::stampUpdate(['id_shift' => $idShift]));
        return $this->findById($record->id_jadwal_shift);
    }

    public function delete(object $record): void
    {
        DB::table('jadwal_shift')
            ->where('id_jadwal_shift', $record->id_jadwal_shift)
            ->update(RecordHelper::stampDelete());
    }
}
```

`JadwalShiftService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use Illuminate\Support\Facades\DB;

class JadwalShiftService
{
    public function __construct(private readonly JadwalShiftRepositoryInterface $repo) {}

    public function list(string $idProyek, string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        if (!$this->repo->proyekMilikPerusahaan($idProyek, $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }
        return $this->repo->listByProyek($idProyek, $dari, $sampai);
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Jadwal shift tidak ditemukan');
        }
        return $record;
    }

    /**
     * Batch assign: satu shift + satu tanggal + banyak supir.
     * Aturan per supir (gagal per-item, bukan gagal total):
     * - wajib punya penugasan internal pending/aktif di proyek;
     * - maks 1 shift per tanggal GLOBAL lintas proyek (soft-delete aware).
     */
    public function createBatch(array $data, string $idPerusahaan): array
    {
        if (!$this->repo->proyekMilikPerusahaan($data['id_proyek'], $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }

        return DB::transaction(function () use ($data) {
            $sukses = 0;
            $gagal  = [];

            foreach (array_unique($data['supir']) as $idSupir) {
                if (!$this->repo->supirPunyaPenugasan($data['id_proyek'], $idSupir)) {
                    $gagal[] = ['id_supir' => $idSupir, 'alasan' => 'Supir tidak ter-assign ke proyek ini'];
                    continue;
                }

                $ada = $this->repo->findAktifBySupirTanggal($idSupir, $data['tanggal']);
                if ($ada !== null) {
                    $gagal[] = [
                        'id_supir' => $idSupir,
                        'alasan'   => "Supir sudah dijadwalkan shift {$ada->shift_nama} (proyek {$ada->nama_proyek}) pada tanggal ini",
                    ];
                    continue;
                }

                $this->repo->create([
                    'id_proyek' => $data['id_proyek'],
                    'id_shift'  => $data['id_shift'],
                    'id_supir'  => $idSupir,
                    'tanggal'   => $data['tanggal'],
                ]);
                $sukses++;
            }

            return ['sukses' => $sukses, 'gagal' => $gagal];
        });
    }

    public function updateShift(string $id, string $idShift): object
    {
        $record = $this->findOrFail($id);
        return $this->repo->updateShift($record, $idShift);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }
}
```

`JadwalShiftController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Helpers\ApiResponse;
use App\Modules\JadwalShift\Requests\StoreJadwalShiftRequest;
use App\Modules\JadwalShift\Requests\UpdateJadwalShiftRequest;
use App\Modules\JadwalShift\Resources\JadwalShiftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class JadwalShiftController extends Controller
{
    public function __construct(private readonly JadwalShiftService $service) {}

    public function index(Request $request): JsonResponse
    {
        Validator::make($request->query(), [
            'id_proyek' => ['required', 'string'],
            'dari'      => ['sometimes', 'date'],
            'sampai'    => ['sometimes', 'date'],
        ])->validate();

        $rows = $this->service->list(
            (string) $request->get('id_proyek'),
            (string) $request->user()->id_perusahaan,
            $request->get('dari'),
            $request->get('sampai')
        );

        return ApiResponse::success(JadwalShiftResource::collection(collect($rows)));
    }

    public function store(StoreJadwalShiftRequest $request): JsonResponse
    {
        $hasil = $this->service->createBatch(
            $request->validated(),
            (string) $request->user()->id_perusahaan
        );
        return ApiResponse::success($hasil, 'Jadwal shift diproses');
    }

    public function update(UpdateJadwalShiftRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateShift($id, (string) $request->validated()['id_shift']);
        return ApiResponse::success(new JadwalShiftResource($record), 'Jadwal shift berhasil diperbarui');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);
        return ApiResponse::success(null, 'Jadwal shift berhasil dihapus');
    }
}
```

`JadwalShiftServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift;

use App\Modules\JadwalShift\Contracts\JadwalShiftRepositoryInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class JadwalShiftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JadwalShiftRepositoryInterface::class, JadwalShiftRepository::class);
        $this->app->bind(JadwalShiftService::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(function () {
                Route::get('jadwal-shift', [JadwalShiftController::class, 'index']);
                Route::post('jadwal-shift', [JadwalShiftController::class, 'store']);
                Route::put('jadwal-shift/{id}', [JadwalShiftController::class, 'update']);
                Route::delete('jadwal-shift/{id}', [JadwalShiftController::class, 'destroy']);
            });
    }
}
```

`Requests/StoreJadwalShiftRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJadwalShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_proyek' => ['required', 'string', 'exists:proyek,id_proyek,dihapus_pada,NULL'],
            'id_shift'  => ['required', 'string', 'exists:shift,id_shift,dihapus_pada,NULL'],
            'tanggal'   => ['required', 'date_format:Y-m-d'],
            'supir'     => ['required', 'array', 'min:1'],
            'supir.*'   => ['required', 'string', 'exists:supir,id_supir,dihapus_pada,NULL'],
        ];
    }
}
```

`Requests/UpdateJadwalShiftRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJadwalShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_shift' => ['required', 'string', 'exists:shift,id_shift,dihapus_pada,NULL'],
        ];
    }
}
```

`Resources/JadwalShiftResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\JadwalShift\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class JadwalShiftResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id_jadwal_shift' => $this->id_jadwal_shift,
            'id_proyek'       => $this->id_proyek,
            'id_shift'        => $this->id_shift,
            'id_supir'        => $this->id_supir,
            'tanggal'         => $this->tanggal,
            'shift_nama'      => $this->shift_nama,
            'jam_mulai'       => $this->jam_mulai,
            'jam_selesai'     => $this->jam_selesai,
        ];
    }
}
```

- [ ] **Step 4:** Daftarkan provider (alfabetis, antara `JadwalKeberangkatan` dan `JenisBbm`). Run focused test → 5/5 PASS. Full suite hijau. Stage:

```bash
git add app/Modules/JadwalShift bootstrap/providers.php tests/Feature/JadwalShiftTest.php
```

---

### Task 3: Frontend — services + konstanta + halaman master `/shift`

**Files (repo frontend):**
- Create: `src/services/shift.service.ts`
- Create: `src/services/jadwalShift.service.ts`
- Modify: `src/constants/api.constant.ts`, `src/constants/route.constant.ts`, `src/configs/routes.config/routes.config.ts` (file-file ini punya bundled pre-existing content — tambah baris milik task ini saja)
- Create: `src/app/(protected-pages)/shift/page.tsx` + `baru/page.tsx` + `[id]/page.tsx`

**Interfaces (Produces):** `shiftService.{list,get,create,update,delete}` + interface `Shift {id_shift,id_perusahaan,nama,jam_mulai,jam_selesai,aktif,dibuat_pada,diubah_pada}`; `jadwalShiftService.{list(idProyek,dari,sampai), create(payload), update(id,{id_shift}), delete(id)}` + interface `JadwalShift {id_jadwal_shift,id_proyek,id_shift,id_supir,tanggal,shift_nama,jam_mulai,jam_selesai}` + `HasilBatchShift {sukses:number, gagal:{id_supir:string, alasan:string}[]}`; `API_ENDPOINTS.SHIFT/SHIFT_DETAIL/JADWAL_SHIFT/JADWAL_SHIFT_DETAIL`; `ROUTES.SHIFT/_BARU/_DETAIL`; `listRoute('shift')` di routes.config.

- [ ] **Step 1: `shift.service.ts`** — mirror `jenisPerawatan.service.ts` (substitusi field: nama, jam_mulai, jam_selesai, aktif; payload create: `{nama, jam_mulai, jam_selesai, aktif?}` — jam format `HH:mm`).
- [ ] **Step 2: `jadwalShift.service.ts`** — kode lengkap:

```ts
import axios from 'axios'
import { API_ENDPOINTS } from '@/constants/api.constant'

export interface JadwalShift {
    id_jadwal_shift: string
    id_proyek: string
    id_shift: string
    id_supir: string
    tanggal: string
    shift_nama: string
    jam_mulai: string
    jam_selesai: string
}

export interface HasilBatchShift {
    sukses: number
    gagal: { id_supir: string; alasan: string }[]
}

export const jadwalShiftService = {
    async list(idProyek: string, dari: string, sampai: string) {
        const { data } = await axios.get(API_ENDPOINTS.JADWAL_SHIFT, { params: { id_proyek: idProyek, dari, sampai } })
        return data.data as JadwalShift[]
    },
    async create(payload: { id_proyek: string; id_shift: string; tanggal: string; supir: string[] }) {
        const { data } = await axios.post(API_ENDPOINTS.JADWAL_SHIFT, payload)
        return data.data as HasilBatchShift
    },
    async update(id: string, payload: { id_shift: string }) {
        const { data } = await axios.put(API_ENDPOINTS.JADWAL_SHIFT_DETAIL(id), payload)
        return data.data as JadwalShift
    },
    async delete(id: string) {
        await axios.delete(API_ENDPOINTS.JADWAL_SHIFT_DETAIL(id))
    },
}
```

- [ ] **Step 3: Konstanta** — `api.constant.ts` (setelah blok SPAREPART): `SHIFT: '/api/proxy/shift'`, `SHIFT_DETAIL: (id) => ...`, `JADWAL_SHIFT: '/api/proxy/jadwal-shift'`, `JADWAL_SHIFT_DETAIL: (id) => ...`. `route.constant.ts`: `SHIFT/_BARU/_DETAIL`. `routes.config.ts`: `...listRoute('shift', 'shift'),` setelah listRoute sparepart.
- [ ] **Step 4: Halaman master `/shift`** — mirror 3 halaman `jenis-perawatan` (baca referensinya): list kolom No/Nama/Jam (format `HH:mm – HH:mm`, potong detik)/Status Aktif/Aksi; form baru & edit: nama (required), jam_mulai + jam_selesai (`<Input type="time">`, required), aktif di edit. Tampilkan hint kecil "Jam selesai lebih kecil dari jam mulai = shift berakhir keesokan hari".
- [ ] **Step 5:** `npx tsc --noEmit` 0 error + eslint bersih pada file baru. Stage:

```bash
git add src/services/shift.service.ts src/services/jadwalShift.service.ts src/constants/api.constant.ts src/constants/route.constant.ts src/configs/routes.config/routes.config.ts "src/app/(protected-pages)/shift"
```

---

### Task 4: Frontend — `PapanShift.tsx` (kotak card) + integrasi + hapus papan lama

**Files:**
- Create: `src/app/(protected-pages)/penugasan/PapanShift.tsx`
- Delete: `src/app/(protected-pages)/penugasan/PapanJadwal.tsx`
- Modify: `src/app/(protected-pages)/penugasan/page.tsx` (HANYA ganti import `PapanJadwal` → `PapanShift` dan pemakaiannya `<PapanJadwal idProyek=.../>` → `<PapanShift idProyek=.../>`; jangan sentuh apa pun lain — file aktif user)

**Interfaces (Consumes):** Task 3 services/types; `penugasanService.list(idProyek,1,'internal',100)`; `supirService.list(1,100)`; `armadaService.list(1,100)`.

- [ ] **Step 1: `PapanShift.tsx`** — kode lengkap:

```tsx
'use client'
import { useEffect, useState, useCallback, useMemo } from 'react'
import { Button, FormItem, toast, Notification, Spinner, Dialog, Input } from '@/components/ui'
import Select from '@/components/ui/Select'
import ConfirmDialog from '@/components/shared/ConfirmDialog'
import { HiOutlinePlus, HiOutlinePencilAlt, HiOutlineTrash, HiOutlineChevronLeft, HiOutlineChevronRight, HiOutlineSearch } from 'react-icons/hi'
import dayjs from 'dayjs'
import { parseApiError } from '@/utils/error.util'
import { jadwalShiftService, JadwalShift } from '@/services/jadwalShift.service'
import { shiftService, Shift } from '@/services/shift.service'
import { penugasanService } from '@/services/penugasan.service'
import { armadaService, Armada } from '@/services/armada.service'
import { supirService, Supir } from '@/services/supir.service'

type Option = { value: string; label: string }

const AVATAR_COLORS = ['#2563eb', '#059669', '#7c3aed', '#db2777', '#d97706', '#0891b2', '#4f46e5', '#65a30d']
const avatarColor = (nama: string) => AVATAR_COLORS[(nama.charCodeAt(0) || 0) % AVATAR_COLORS.length]

const HARI = ['MIN', 'SEN', 'SEL', 'RAB', 'KAM', 'JUM', 'SAB']

const jam = (t: string) => t.slice(0, 5) // "08:00:00" -> "08:00"

type BarisSupir = { idSupir: string; nama: string; nopol: string | null }

export default function PapanShift({ idProyek }: { idProyek: string }) {
    const [bulan, setBulan]   = useState(dayjs().startOf('month'))
    const [loading, setLoading] = useState(false)

    const [barisSupir, setBarisSupir]   = useState<BarisSupir[]>([])
    const [jadwalList, setJadwalList]   = useState<JadwalShift[]>([])
    const [shiftList, setShiftList]     = useState<Shift[]>([])
    const [cariSupir, setCariSupir]     = useState('')

    // dialog assign (sel kosong) / ganti shift (ikon pensil)
    const [dialogTarget, setDialogTarget] = useState<{ supir: BarisSupir; tanggal: string; jadwal?: JadwalShift } | null>(null)
    const [pilihShift, setPilihShift]     = useState<string | null>(null)
    const [saving, setSaving]             = useState(false)

    const [deleteTarget, setDeleteTarget] = useState<JadwalShift | null>(null)
    const [deleting, setDeleting]         = useState(false)

    useEffect(() => {
        shiftService.list(1, 100)
            .then(res => setShiftList(res.data.filter((s: Shift) => s.aktif)))
            .catch(() => {})
    }, [])

    const fetchBoard = useCallback(async () => {
        if (!idProyek) return
        setLoading(true)
        try {
            const dari   = bulan.format('YYYY-MM-DD')
            const sampai = bulan.endOf('month').format('YYYY-MM-DD')
            const [penugasan, jadwal, supirRes, armadaRes] = await Promise.all([
                penugasanService.list(idProyek, 1, 'internal', 100),
                jadwalShiftService.list(idProyek, dari, sampai),
                supirService.list(1, 100),
                armadaService.list(1, 100),
            ])
            const supirMap: Record<string, Supir> = {}
            supirRes.data.forEach((s: Supir) => { supirMap[s.id_supir] = s })
            const armadaMap: Record<string, Armada> = {}
            armadaRes.data.forEach((a: Armada) => { armadaMap[a.id_armada] = a })

            const unik = new Map<string, BarisSupir>()
            penugasan.data
                .filter(p => (p.status === 'pending' || p.status === 'aktif') && p.id_supir)
                .forEach(p => {
                    if (unik.has(p.id_supir!)) return
                    const s = supirMap[p.id_supir!]
                    unik.set(p.id_supir!, {
                        idSupir: p.id_supir!,
                        nama: s?.nama ?? p.id_supir!.slice(0, 8),
                        nopol: p.id_armada ? (armadaMap[p.id_armada]?.nopol ?? null) : null,
                    })
                })
            setBarisSupir(Array.from(unik.values()).sort((a, b) => a.nama.localeCompare(b.nama)))
            setJadwalList(jadwal)
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setLoading(false)
        }
    }, [idProyek, bulan])

    useEffect(() => { fetchBoard() }, [fetchBoard])

    const tanggalList = useMemo(() => {
        const n = bulan.daysInMonth()
        return Array.from({ length: n }, (_, i) => bulan.date(i + 1))
    }, [bulan])

    // jadwal per supir per tanggal (maks 1 — aturan backend)
    const jadwalMap = useMemo(() => {
        const m: Record<string, Record<string, JadwalShift>> = {}
        jadwalList.forEach(j => {
            m[j.id_supir] ??= {}
            m[j.id_supir][j.tanggal] = j
        })
        return m
    }, [jadwalList])

    const barisTampil = useMemo(() => {
        const q = cariSupir.trim().toLowerCase()
        if (!q) return barisSupir
        return barisSupir.filter(b => b.nama.toLowerCase().includes(q) || (b.nopol ?? '').toLowerCase().includes(q))
    }, [barisSupir, cariSupir])

    const shiftOptions: Option[] = shiftList.map(s => ({
        value: s.id_shift,
        label: `${s.nama} — ${jam(s.jam_mulai)}-${jam(s.jam_selesai)}`,
    }))

    const countShift = (idSupir: string) => Object.keys(jadwalMap[idSupir] ?? {}).length

    const bukaAssign = (supir: BarisSupir, tanggal: string) => {
        setDialogTarget({ supir, tanggal })
        setPilihShift(null)
    }

    const bukaGanti = (supir: BarisSupir, jadwal: JadwalShift) => {
        setDialogTarget({ supir, tanggal: jadwal.tanggal, jadwal })
        setPilihShift(jadwal.id_shift)
    }

    const handleSubmit = async () => {
        if (!dialogTarget || !pilihShift) return
        setSaving(true)
        try {
            if (dialogTarget.jadwal) {
                await jadwalShiftService.update(dialogTarget.jadwal.id_jadwal_shift, { id_shift: pilihShift })
                toast.push(<Notification type="success" title="Shift berhasil diganti" />)
            } else {
                const hasil = await jadwalShiftService.create({
                    id_proyek: idProyek,
                    id_shift: pilihShift,
                    tanggal: dialogTarget.tanggal,
                    supir: [dialogTarget.supir.idSupir],
                })
                if (hasil.gagal.length > 0) {
                    toast.push(<Notification type="danger" title={hasil.gagal[0].alasan} />)
                } else {
                    toast.push(<Notification type="success" title="Shift berhasil dijadwalkan" />)
                }
            }
            setDialogTarget(null)
            fetchBoard()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setSaving(false)
        }
    }

    const handleDelete = async () => {
        if (!deleteTarget) return
        setDeleting(true)
        try {
            await jadwalShiftService.delete(deleteTarget.id_jadwal_shift)
            toast.push(<Notification type="success" title="Jadwal shift berhasil dihapus" />)
            setDeleteTarget(null)
            fetchBoard()
        } catch (err) {
            toast.push(<Notification type="danger" title={parseApiError(err)} />)
        } finally {
            setDeleting(false)
        }
    }

    const hariIni = dayjs().format('YYYY-MM-DD')

    return (
        <div className="p-4 flex flex-col gap-4">
            <div className="flex items-center justify-end gap-2">
                <Button size="sm" variant="default" icon={<HiOutlineChevronLeft />}
                    onClick={() => setBulan(b => b.subtract(1, 'month'))} />
                <span className="font-semibold min-w-[140px] text-center">{bulan.format('MMMM YYYY')}</span>
                <Button size="sm" variant="default" icon={<HiOutlineChevronRight />}
                    onClick={() => setBulan(b => b.add(1, 'month'))} />
            </div>

            {loading ? (
                <div className="flex justify-center py-16"><Spinner size={36} /></div>
            ) : barisSupir.length === 0 ? (
                <p className="text-gray-400 text-sm py-10 text-center">
                    Belum ada supir ter-assign di proyek ini — buat penugasan dulu di tab Tabel.
                </p>
            ) : (
                <div className="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg shadow-xs">
                    <table className="border-separate border-spacing-0 min-w-full">
                        <thead className="sticky top-0 z-10">
                            <tr>
                                <th className="sticky left-0 z-20 bg-gray-50 dark:bg-gray-800 text-left px-3 py-2 min-w-[220px] border-b border-r border-gray-200 dark:border-gray-700">
                                    <Input size="sm" placeholder="Cari nama supir / nopol..."
                                        prefix={<HiOutlineSearch className="text-gray-400" />}
                                        value={cariSupir}
                                        onChange={e => setCariSupir(e.target.value)} />
                                </th>
                                {tanggalList.map(t => {
                                    const isToday = t.format('YYYY-MM-DD') === hariIni
                                    return (
                                        <th key={t.date()} className="text-center px-2 py-2 min-w-[132px] bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                                            <div className={`text-[10px] font-semibold tracking-wide ${isToday ? 'text-blue-600' : 'text-gray-400'}`}>
                                                {HARI[t.day()]}
                                            </div>
                                            <div className={`mt-0.5 text-sm font-bold inline-flex items-center justify-center ${
                                                isToday ? 'w-7 h-7 rounded-full bg-blue-600 text-white' : 'text-gray-700 dark:text-gray-200'
                                            }`}>
                                                {t.date()}
                                            </div>
                                        </th>
                                    )
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {barisTampil.map(b => {
                                const warna = avatarColor(b.nama)
                                return (
                                    <tr key={b.idSupir}>
                                        <td className="sticky left-0 z-10 px-3 py-3 bg-white dark:bg-gray-900 border-b border-r border-gray-200 dark:border-gray-700 align-top">
                                            <div className="flex items-center gap-3">
                                                <span className="w-9 h-9 flex items-center justify-center rounded-full font-bold text-sm shrink-0"
                                                    style={{ color: warna, backgroundColor: warna + '15', border: `2px solid ${warna}` }}>
                                                    {b.nama.charAt(0).toUpperCase()}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="font-semibold text-sm truncate uppercase">{b.nama}</p>
                                                    <p className="text-xs text-gray-400 font-mono truncate">{b.nopol ?? '—'}</p>
                                                    <p className="text-[11px] text-gray-400">{countShift(b.idSupir)} shift</p>
                                                </div>
                                            </div>
                                        </td>
                                        {tanggalList.map(t => {
                                            const key = t.format('YYYY-MM-DD')
                                            const j = jadwalMap[b.idSupir]?.[key]
                                            return (
                                                <td key={key} className="px-1.5 py-2 border-b border-gray-100 dark:border-gray-700 align-middle">
                                                    {j ? (
                                                        <div className="rounded-lg border border-blue-200 dark:border-blue-500/30 bg-blue-50/60 dark:bg-blue-500/10 px-2 py-1.5">
                                                            <div className="flex items-center justify-between gap-1">
                                                                <span className="text-[10px] font-semibold text-gray-500 dark:text-gray-300 uppercase truncate">{j.shift_nama}</span>
                                                                <span className="flex items-center shrink-0">
                                                                    <button type="button" className="p-0.5 text-blue-500 hover:text-blue-700"
                                                                        onClick={() => bukaGanti(b, j)}>
                                                                        <HiOutlinePencilAlt className="w-3.5 h-3.5" />
                                                                    </button>
                                                                    <button type="button" className="p-0.5 text-red-400 hover:text-red-600"
                                                                        onClick={() => setDeleteTarget(j)}>
                                                                        <HiOutlineTrash className="w-3.5 h-3.5" />
                                                                    </button>
                                                                </span>
                                                            </div>
                                                            <p className="text-sm font-bold text-blue-600 dark:text-blue-300 whitespace-nowrap">
                                                                {jam(j.jam_mulai)} - {jam(j.jam_selesai)}
                                                            </p>
                                                        </div>
                                                    ) : (
                                                        <button type="button"
                                                            className="w-full h-12 rounded-lg border border-dashed border-transparent hover:border-blue-300 text-transparent hover:text-blue-400 flex items-center justify-center transition-colors"
                                                            onClick={() => bukaAssign(b, key)}>
                                                            <HiOutlinePlus className="w-4 h-4" />
                                                        </button>
                                                    )}
                                                </td>
                                            )
                                        })}
                                    </tr>
                                )
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Dialog assign / ganti shift */}
            <Dialog isOpen={!!dialogTarget} onRequestClose={() => setDialogTarget(null)} width={440}>
                <h5 className="text-base font-semibold mb-1">{dialogTarget?.jadwal ? 'Ganti Shift' : 'Assign Shift'}</h5>
                <p className="text-xs text-gray-400 mb-4">
                    {dialogTarget && `${dialogTarget.supir.nama} — ${dayjs(dialogTarget.tanggal).format('dddd, DD MMMM YYYY')}`}
                </p>
                <form onSubmit={e => { e.preventDefault(); handleSubmit() }}>
                    <FormItem label="Shift" asterisk>
                        <Select placeholder="Pilih shift..."
                            options={shiftOptions}
                            value={shiftOptions.find(o => o.value === pilihShift) ?? null}
                            onChange={opt => setPilihShift((opt as Option | null)?.value ?? null)} />
                    </FormItem>
                    {shiftList.length === 0 && (
                        <p className="text-xs text-amber-600 -mt-2 mb-2">Belum ada master Shift — buat dulu di menu Data Master → Shift.</p>
                    )}
                    <div className="flex justify-end gap-2 mt-4">
                        <Button type="button" variant="plain" onClick={() => setDialogTarget(null)}>Batal</Button>
                        <Button type="submit" variant="solid" loading={saving} disabled={!pilihShift}>Simpan</Button>
                    </div>
                </form>
            </Dialog>

            <ConfirmDialog isOpen={!!deleteTarget} type="danger" title="Hapus Jadwal Shift"
                confirmText="Ya, Hapus" cancelText="Batal"
                onClose={() => setDeleteTarget(null)}
                onCancel={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                confirmButtonProps={{ loading: deleting }}>
                <p>Hapus shift <strong>{deleteTarget?.shift_nama}</strong> tanggal <strong>{deleteTarget ? dayjs(deleteTarget.tanggal).format('DD MMM YYYY') : ''}</strong>?</p>
            </ConfirmDialog>
        </div>
    )
}
```

- [ ] **Step 2:** Hapus `PapanJadwal.tsx` (`git rm`) dan di `penugasan/page.tsx` ganti import + pemakaian ke `PapanShift` (2 baris saja).
- [ ] **Step 3:** `npx tsc --noEmit` 0 error; eslint bersih pada `penugasan/`. Stage:

```bash
git add "src/app/(protected-pages)/penugasan/PapanShift.tsx" "src/app/(protected-pages)/penugasan/page.tsx"
git rm --cached "src/app/(protected-pages)/penugasan/PapanJadwal.tsx" 2>/dev/null || git add "src/app/(protected-pages)/penugasan/PapanJadwal.tsx"
```

(Catatan: `PapanJadwal.tsx` hanya pernah staged, belum pernah commit — setelah file dihapus dari disk, `git add` path tsb akan mencatat penghapusannya dari index.)

---

### Task 5: Verifikasi penuh end-to-end

**Files:** tidak ada — operasional (controller langsung).

- [ ] **Step 1:** Full backend suite hijau (naik ±9 test: Shift 4 + JadwalShift 5).
- [ ] **Step 2:** Rebuild + restart Docker backend & frontend; cek migration 000007-000009 terkonfirmasi di MySQL (`Schema::hasTable('shift')`, menu `...057` ada di Data Master).
- [ ] **Step 3:** Smoke live (login superadmin): CRUD `/api/v1/shift` (create → 201), `POST jadwal-shift` siklus penuh (buat proyek+penugasan seed? — cukup: GET jadwal-shift dengan id_proyek asli → 200; POST dengan data asli bila tersedia), `menu/tree` memuat "Shift" di Data Master.
- [ ] **Step 4:** Frontend `/shift` & `/penugasan` → 302 (expected). Grep: nol referensi `PapanJadwal` tersisa di frontend.
- [ ] **Step 5:** JANGAN commit — laporkan daftar staged kedua repo.
