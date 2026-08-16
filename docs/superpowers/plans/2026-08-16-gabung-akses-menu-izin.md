# Gabung Akses Menu ke Peran & Akses — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sidebar dibangun dari izin aksi `lihat` di `izin_peran`; halaman & jalur Akses Menu (`menu_peran`) dihapus, satu tempat pengelolaan di Peran & Akses.

**Architecture:** `MenuRepository::tree()` difilter dari `izin_peran` (presedensi identik `CheckIzinPeran`: baris per-perusahaan menang, SUPERADMIN bypass); migrasi satu kali mematerialisasi visibilitas `menu_peran` lama menjadi baris izin `lihat` global; endpoint/halaman akses-menu dihapus; tabel `menu_peran` dibiarkan (tidak dibaca lagi).

**Tech Stack:** Laravel 11 (modul Menu, sqlite in-memory tests), Next.js 15.

**Spec:** `docs/superpowers/specs/2026-08-16-gabung-akses-menu-izin-design.md`

## Global Constraints

- DILARANG `git commit`/`git add`/git yang mengubah state — checkpoint = laporan diff. DILARANG build (docker/npm). Test backend HANYA `vendor/bin/phpunit`.
- DILARANG komentar penjelas di kode (komentar yang menyatakan constraint tak terlihat dari kode boleh, gaya file existing).
- Presedensi izin WAJIB identik `CheckIzinPeran`: baris `id_perusahaan = perusahaan user` menang atas baris `id_perusahaan IS NULL`, termasuk revoke (`diizinkan = 0`); `kode_peran` dibandingkan case-insensitive (UPPER dua sisi).
- `SUPERADMIN` (atau `kodePeran null`) → semua menu aktif tampil tanpa cek izin.
- Menu grup (path NULL) tampil hanya bila punya minimal satu turunan tampil.
- Baris `izin_peran` yang sudah ada (termasuk revoke) TIDAK boleh ditimpa migrasi.
- Tabel `menu_peran` TIDAK di-drop dan TIDAK dibaca lagi di jalur tree.

---

### Task 1: Backend — tree berbasis izin, hapus jalur akses-peran, migrasi materialisasi

**Files:**
- Modify: `app/Modules/Menu/MenuRepository.php` (tree ~baris 90-107; hapus `allWithPerans`, `semuaKodePeran`, `sinkronAksesPeran`)
- Modify: `app/Modules/Menu/Contracts/MenuRepositoryInterface.php` (signature tree; hapus signature method yang dihapus)
- Modify: `app/Modules/Menu/MenuService.php` (tree passthrough; hapus `aksesPeran`, `simpanAksesPeran`)
- Modify: `app/Modules/Menu/MenuController.php` (tree kirim id_perusahaan; hapus `aksesPeran`, `simpanAksesPeran` + import tak terpakai)
- Modify: `app/Modules/Menu/MenuServiceProvider.php` (hapus 2 route akses-peran)
- Delete: `app/Modules/Menu/MenuPeran.php` + relasi `perans()` di `MenuModel.php` (setelah grep pemakai = 0)
- Create: `database/migrations/2026_08_16_110001_materialisasi_izin_lihat_dari_menu_peran.php`
- Test: `tests/Feature/GabungAksesMenuIzinTest.php` (baru); Delete: `tests/Feature/MenuAksesPeranTest.php`

**Interfaces:**
- Consumes: `izin_peran` (id_izin, id_perusahaan NULL-able, kode_peran, id_menu, aksi, diizinkan, audit); `MenuModel::active()`, `buildTree(Collection, ?string)` (tidak berubah).
- Produces: `tree(?string $kodePeran = null, ?string $idPerusahaan = null): array` — dipakai `MenuController::tree` (respons `menu/tree` bentuknya TIDAK berubah).

- [ ] **Step 1: Tulis failing tests** — `tests/Feature/GabungAksesMenuIzinTest.php`, setup gaya feature test lain (`RefreshDatabase`, `actingAsRole`, `self::PERUSAHAAN_ID`). Helper:

```php
    private function buatMenu(?string $idInduk, ?string $path, string $nama, int $urutan = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('menu')->insert([
            'id_menu' => $id, 'nama_menu' => $nama, 'path' => $path,
            'id_menu_induk' => $idInduk, 'icon' => 'truck', 'urutan' => $urutan,
            'aktif' => 1, 'dibuat_pada' => now(),
        ]);
        return $id;
    }

    private function beriIzinLihat(string $idMenu, string $kodePeran, int $diizinkan = 1, ?string $idPerusahaan = null): void
    {
        DB::table('izin_peran')->insert([
            'id_izin' => (string) Str::uuid(), 'id_perusahaan' => $idPerusahaan,
            'kode_peran' => $kodePeran, 'id_menu' => $idMenu, 'aksi' => 'lihat',
            'diizinkan' => $diizinkan, 'dibuat_pada' => now(),
        ]);
    }

    private function namaMenuTree(): array
    {
        $res = $this->getJson('/api/v1/menu/tree')->assertStatus(200);
        $ambil = function (array $nodes) use (&$ambil): array {
            $hasil = [];
            foreach ($nodes as $n) {
                $hasil[] = $n['nama_menu'];
                $hasil = array_merge($hasil, $ambil($n['children'] ?? []));
            }
            return $hasil;
        };
        return $ambil($res->json('data'));
    }
```

Test methods:

```php
    public function test_tree_hanya_memuat_menu_dengan_izin_lihat_dan_grup_induknya(): void
    {
        $grup  = $this->buatMenu(null, null, 'Grup Uji');
        $anak1 = $this->buatMenu($grup, '/uji-satu', 'Uji Satu');
        $anak2 = $this->buatMenu($grup, '/uji-dua', 'Uji Dua', 2);
        $this->buatMenu($grup, '/uji-tiga', 'Uji Tiga', 3);
        $this->beriIzinLihat($anak1, 'MANAGER');
        $this->beriIzinLihat($anak2, 'MANAGER');

        $this->actingAsRole('MANAGER');
        $nama = $this->namaMenuTree();
        $this->assertContains('Grup Uji', $nama);
        $this->assertContains('Uji Satu', $nama);
        $this->assertContains('Uji Dua', $nama);
        $this->assertNotContains('Uji Tiga', $nama);
    }

    public function test_grup_tanpa_anak_tampil_ikut_hilang(): void
    {
        $grup = $this->buatMenu(null, null, 'Grup Kosong');
        $this->buatMenu($grup, '/uji-empat', 'Uji Empat');

        $this->actingAsRole('MANAGER');
        $nama = $this->namaMenuTree();
        $this->assertNotContains('Grup Kosong', $nama);
        $this->assertNotContains('Uji Empat', $nama);
    }

    public function test_revoke_per_perusahaan_menang_atas_global(): void
    {
        $menu = $this->buatMenu(null, '/uji-lima', 'Uji Lima');
        $this->beriIzinLihat($menu, 'MANAGER', 1, null);
        $this->beriIzinLihat($menu, 'MANAGER', 0, self::PERUSAHAAN_ID);

        $this->actingAsRole('MANAGER');
        $this->assertNotContains('Uji Lima', $this->namaMenuTree());
    }

    public function test_superadmin_melihat_semua_menu_aktif_tanpa_baris_izin(): void
    {
        $this->buatMenu(null, '/uji-enam', 'Uji Enam');

        $this->actingAsRole('SUPERADMIN');
        $this->assertContains('Uji Enam', $this->namaMenuTree());
    }

    public function test_endpoint_akses_peran_sudah_hilang(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $this->getJson('/api/v1/menu/akses-peran')->assertStatus(404);
        $this->putJson('/api/v1/menu/akses-peran/MANAGER', ['id_menu' => []])->assertStatus(404);
    }

    public function test_migrasi_materialisasi_menyalin_menu_peran_tanpa_menimpa_revoke(): void
    {
        $terbuka  = $this->buatMenu(null, '/uji-terbuka', 'Uji Terbuka');
        $terbatas = $this->buatMenu(null, '/uji-terbatas', 'Uji Terbatas');
        $revoked  = $this->buatMenu(null, '/uji-revoked', 'Uji Revoked');
        DB::table('menu_peran')->insert([
            ['id_menu' => $terbatas, 'kode_peran' => 'MANAGER'],
            ['id_menu' => $revoked, 'kode_peran' => 'MANAGER'],
        ]);
        $this->beriIzinLihat($revoked, 'MANAGER', 0, null);

        $migration = require database_path('migrations/2026_08_16_110001_materialisasi_izin_lihat_dari_menu_peran.php');
        $migration->up();

        $izin = fn (string $idMenu, string $kode) => DB::table('izin_peran')
            ->where('id_menu', $idMenu)->whereRaw('UPPER(kode_peran) = ?', [$kode])
            ->where('aksi', 'lihat')->whereNull('id_perusahaan')->whereNull('dihapus_pada')->get();

        $this->assertSame(1, (int) $izin($terbuka, 'MANAGER')->first()->diizinkan);
        $this->assertSame(1, (int) $izin($terbuka, 'KEUANGAN')->first()->diizinkan);
        $this->assertSame(1, (int) $izin($terbatas, 'MANAGER')->first()->diizinkan);
        $this->assertCount(0, $izin($terbatas, 'KEUANGAN'));
        $this->assertCount(1, $izin($revoked, 'MANAGER'));
        $this->assertSame(0, (int) $izin($revoked, 'MANAGER')->first()->diizinkan);
        $this->assertCount(0, $izin($terbuka, 'SUPERADMIN'));

        $migration->up();
        $this->assertCount(1, $izin($terbuka, 'MANAGER'));
    }
```

Catatan implementer: tabel `peran` pada environment test harus memuat peran yang dipakai (`MANAGER`, `KEUANGAN`) — cek seeder yang dijalankan `tests/TestCase.php`; bila tidak tersedia, insert baris `peran` di helper test.

- [ ] **Step 2: Run FAIL** — `vendor/bin/phpunit --filter=GabungAksesMenuIzinTest` (endpoint 404 test juga FAIL karena route masih ada).

- [ ] **Step 3: Implementasi.**

`MenuRepository::tree` (ganti baris 90-107):

```php
    public function tree(?string $kodePeran = null, ?string $idPerusahaan = null): array
    {
        $all = MenuModel::active()->where('aktif', 1)->orderBy('urutan')->get();

        if ($kodePeran !== null && strtoupper($kodePeran) !== 'SUPERADMIN') {
            $izinRows = DB::table('izin_peran')
                ->whereRaw('UPPER(kode_peran) = ?', [strtoupper($kodePeran)])
                ->where('aksi', 'lihat')
                ->whereNull('dihapus_pada')
                ->where(function ($q) use ($idPerusahaan) {
                    $q->where('id_perusahaan', $idPerusahaan)->orWhereNull('id_perusahaan');
                })
                ->get(['id_menu', 'diizinkan', 'id_perusahaan'])
                ->groupBy('id_menu');

            $bolehLihat = [];
            foreach ($izinRows as $idMenu => $rows) {
                $baris = $rows->first(fn ($r) => $r->id_perusahaan !== null)
                    ?? $rows->first(fn ($r) => $r->id_perusahaan === null);
                if ($baris !== null && (int) $baris->diizinkan === 1) {
                    $bolehLihat[$idMenu] = true;
                }
            }

            $byId   = $all->keyBy('id_menu');
            $tampil = [];
            foreach ($all as $m) {
                if ($m->path === null || !isset($bolehLihat[$m->id_menu])) {
                    continue;
                }
                $cur = $m;
                while ($cur !== null && !isset($tampil[$cur->id_menu])) {
                    $tampil[$cur->id_menu] = true;
                    $cur = $cur->id_menu_induk !== null ? ($byId[$cur->id_menu_induk] ?? null) : null;
                }
            }
            $all = $all->filter(fn ($m) => isset($tampil[$m->id_menu]))->values();
        }

        return $this->buildTree($all, null);
    }
```

`MenuService::tree(?string $kodePeran = null, ?string $idPerusahaan = null)` passthrough. `MenuController::tree`:

```php
    public function tree(): JsonResponse
    {
        $user = auth()->user();
        $data = $this->service->tree($user?->kode_peran, $user?->id_perusahaan !== null ? (string) $user->id_perusahaan : null);
        return ApiResponse::success(MenuResource::collection($data));
    }
```

Hapus: route `GET menu/akses-peran` + `PUT menu/akses-peran/{kodePeran}` di ServiceProvider; `MenuController::aksesPeran/simpanAksesPeran`; `MenuService::aksesPeran/simpanAksesPeran`; `MenuRepository::allWithPerans/semuaKodePeran/sinkronAksesPeran` + signature di interface; relasi `perans()` di `MenuModel`; file `MenuPeran.php`. Sebelum hapus, grep `perans\b|allWithPerans|semuaKodePeran|sinkronAksesPeran|MenuPeran` di seluruh `app/` — pemakai harus 0 tersisa.

Migration `database/migrations/2026_08_16_110001_materialisasi_izin_lihat_dari_menu_peran.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $perans = DB::table('peran')->whereNull('dihapus_pada')
            ->pluck('kode_peran')
            ->map(fn ($k) => strtoupper((string) $k))
            ->unique()
            ->reject(fn ($k) => $k === 'SUPERADMIN')
            ->values()->all();

        $menuPeran = DB::table('menu_peran')->get()->groupBy('id_menu');
        $izinAda = DB::table('izin_peran')
            ->where('aksi', 'lihat')->whereNull('id_perusahaan')->whereNull('dihapus_pada')
            ->get(['id_menu', 'kode_peran'])
            ->map(fn ($r) => $r->id_menu . '|' . strtoupper((string) $r->kode_peran))
            ->flip();

        $baru = [];
        foreach (DB::table('menu')->whereNull('dihapus_pada')->where('aktif', 1)->whereNotNull('path')->get(['id_menu']) as $m) {
            $rows    = ($menuPeran[$m->id_menu] ?? collect())->map(fn ($r) => strtoupper((string) $r->kode_peran));
            $terbuka = $rows->isEmpty();
            foreach ($perans as $kode) {
                if (!$terbuka && !$rows->contains($kode)) continue;
                if (isset($izinAda[$m->id_menu . '|' . $kode])) continue;
                $baru[] = [
                    'id_izin' => (string) Str::uuid(), 'id_perusahaan' => null,
                    'kode_peran' => $kode, 'id_menu' => $m->id_menu,
                    'aksi' => 'lihat', 'diizinkan' => 1, 'dibuat_pada' => $now,
                ];
            }
        }
        foreach (array_chunk($baru, 500) as $chunk) {
            DB::table('izin_peran')->insert($chunk);
        }

        DB::table('menu')->where('path', '/akses-menu')->whereNull('dihapus_pada')
            ->update(['dihapus_pada' => $now]);
    }

    public function down(): void
    {
        DB::table('menu')->where('path', '/akses-menu')->update(['dihapus_pada' => null]);
    }
};
```

Hapus file `tests/Feature/MenuAksesPeranTest.php`.

- [ ] **Step 4: Run PASS** — `vendor/bin/phpunit --filter=GabungAksesMenuIzinTest`.
- [ ] **Step 5: Regression** — `vendor/bin/phpunit --filter="Menu|Peran|Izin"` lalu FULL `vendor/bin/phpunit`. Expected: PASS semua (test lama yang mengakses `menu/tree` via sidebar tidak ada; bila ada test lain yang membuat asumsi menu_peran → sesuaikan seperlunya dan laporkan).
- [ ] **Step 6: Checkpoint** — laporkan diff (tanpa commit).

---

### Task 2: Frontend — hapus halaman Akses Menu + keterangan di Peran & Akses

**Files:**
- Delete: `src/app/(protected-pages)/akses-menu/page.tsx` (beserta folder), `src/services/aksesMenu.service.ts`
- Modify: `src/constants/api.constant.ts` (hapus `MENU_AKSES_PERAN`, `MENU_AKSES_PERAN_SIMPAN`)
- Modify: `src/constants/route.constant.ts` (hapus `AKSES_MENU: '/akses-menu'`)
- Modify: `src/configs/routes.config/routes.config.ts` (hapus entri `'/akses-menu'`)
- Modify: `src/app/(protected-pages)/peran/[id]/page.tsx` (tambah keterangan)

**Interfaces:**
- Consumes: tidak ada dari Task 1 (respons `menu/tree` tidak berubah bentuk).
- Produces: tidak ada.

- [ ] **Step 1: Hapus file & entri.** Hapus folder `akses-menu`, file `aksesMenu.service.ts`, dua konstanta `MENU_AKSES_PERAN*`, konstanta `ROUTES.AKSES_MENU`, entri `'/akses-menu'` di routes.config. Lalu grep `akses-menu|aksesMenu|AKSES_MENU|MENU_AKSES_PERAN` di seluruh `src/` — sisa pemakai harus 0 (bila navigation.config atau file lain masih memuat entri, hapus juga).

- [ ] **Step 2: Keterangan di Peran & Akses.** Di `peran/[id]/page.tsx`, di dekat judul/subjudul matrix izin (cari heading halaman), tambah satu baris teks kecil gaya existing:

```tsx
<p className="text-xs text-gray-400 mt-0.5">Centang &quot;Lihat&quot; juga menentukan menu yang tampil di sidebar peran ini.</p>
```

Sesuaikan penempatan dengan struktur heading yang ada (subtitle di bawah judul halaman atau di atas tabel matrix — ikuti pola subtitle halaman itu).

- [ ] **Step 3: Lint** — `npx eslint` pada file yang diubah + `npx tsc --noEmit` bila cepat; DILARANG `npm run build`.
- [ ] **Step 4: Checkpoint** — laporkan diff (tanpa commit).
