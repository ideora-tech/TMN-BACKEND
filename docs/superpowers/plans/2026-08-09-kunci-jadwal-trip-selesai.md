# Kunci Jadwal Ber-Trip Selesai/Berjalan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jadwal shift pada tanggal yang tripnya sudah selesai atau sedang berjalan tidak bisa dihapus, diganti shift-nya, maupun ditimpa import.

**Architecture:** Satu helper privat di `JadwalShiftService` membungkus `TripRepositoryInterface::statusTripPerSupirTanggal` (existing, scope per proyek, `belum_mulai`→`berjalan`) untuk 1 supir + 1 tanggal; tiga jalur tulis (delete, updateShift, importMatriks-timpa) memanggilnya sebelum mengubah data. Guard lama (hari ini + trip aktif lintas proyek) tetap.

**Tech Stack:** Laravel 11, PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-08-09-kunci-jadwal-trip-selesai-design.md`

## Global Constraints

- **DILARANG `git commit` / build / migrate** — user jalankan sendiri.
- Test: `vendor/bin/phpunit` (JANGAN `php artisan test`).
- Query hanya di Repository (helper service hanya memanggil repo existing).
- Tanpa komentar penjelas baru kecuali menyatakan constraint; pesan error bahasa Indonesia.

---

### Task 1: Guard `delete()` + `updateShift()`

**Files:**
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/JadwalShiftService.php` (method `delete`, `updateShift`, + helper privat baru)
- Test: `TMN-TRANSPORT-BACKEND/tests/Feature/JadwalShiftTest.php` (3 test baru; pakai helper existing `makeProyek/makeSupir/makePenugasan/makeShift` + `JadwalKeberangkatanModel`/`TripModel` seperti `test_list_menyertakan_status_trip_berjalan_dan_selesai`)

**Interfaces:**
- Consumes: `TripRepositoryInterface::statusTripPerSupirTanggal(string $idProyek, array $idSupirList, string $dari, string $sampai): array` — sudah di-inject sebagai `$this->tripRepo`.
- Produces: helper privat `statusTripUntukJadwal(object $record): ?string` (return `'berjalan'|'selesai'|null`) — dipakai juga Task 2.

- [x] **Step 1: Tulis 3 failing test**

Tambahkan di `JadwalShiftTest` (sesuaikan pemanggilan helper dengan signature existing di file; pola pembuatan trip contek `test_list_menyertakan_status_trip_berjalan_dan_selesai`):

```php
    public function test_hapus_jadwal_dengan_trip_selesai_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Supir Kunci Selesai');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);
        $idJadwal = (string) DB::table('jadwal_shift')->where('id_supir', $supir)->value('id_jadwal_shift');

        $jk = JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan, 'waktu_berangkat' => '2026-07-20 08:00:00',
        ]);
        TripModel::create([
            'id_jadwal' => $jk->id_jadwal, 'status' => 'selesai',
            'waktu_checkin' => '2026-07-20 08:05:00', 'waktu_checkout' => '2026-07-20 17:00:00',
        ]);

        $res = $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}");
        $res->assertStatus(422);
        $this->assertStringContainsString('sudah selesai', (string) $res->json('message'));
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $idJadwal, 'dihapus_pada' => null]);
    }

    public function test_hapus_jadwal_dengan_trip_berjalan_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Supir Kunci Jalan');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir);
        $shift = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shift,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);
        $idJadwal = (string) DB::table('jadwal_shift')->where('id_supir', $supir)->value('id_jadwal_shift');

        $jk = JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan, 'waktu_berangkat' => '2026-07-20 08:00:00',
        ]);
        TripModel::create([
            'id_jadwal' => $jk->id_jadwal, 'status' => 'berjalan', 'waktu_checkin' => '2026-07-20 08:05:00',
        ]);

        $res = $this->deleteJson("/api/v1/jadwal-shift/{$idJadwal}");
        $res->assertStatus(422);
        $this->assertStringContainsString('sedang berjalan', (string) $res->json('message'));
    }

    public function test_ganti_shift_jadwal_dengan_trip_selesai_ditolak(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $supir = $this->makeSupir('Supir Kunci Ganti');
        $penugasan = $this->makePenugasan($proyek->id_proyek, $supir);
        $shiftA = $this->makeShift();
        $shiftB = $this->makeShift();

        $this->postJson('/api/v1/jadwal-shift', [
            'id_proyek' => $proyek->id_proyek, 'id_shift' => $shiftA,
            'tanggal' => '2026-07-20', 'supir' => [$supir],
        ])->assertJsonPath('data.sukses', 1);
        $idJadwal = (string) DB::table('jadwal_shift')->where('id_supir', $supir)->value('id_jadwal_shift');

        $jk = JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan, 'waktu_berangkat' => '2026-07-20 08:00:00',
        ]);
        TripModel::create([
            'id_jadwal' => $jk->id_jadwal, 'status' => 'selesai',
            'waktu_checkin' => '2026-07-20 08:05:00', 'waktu_checkout' => '2026-07-20 17:00:00',
        ]);

        $this->putJson("/api/v1/jadwal-shift/{$idJadwal}", ['id_shift' => $shiftB])->assertStatus(422);
        $this->assertDatabaseHas('jadwal_shift', ['id_jadwal_shift' => $idJadwal, 'id_shift' => $shiftA]);
    }
```

Catatan eksekusi: cek dulu signature `makeShift()`/`makePenugasan()` dan verb update (PUT/PATCH, lihat route `apiResource`) di file test/provider — sesuaikan bila beda. Jadwal tanpa trip masih bisa dihapus — sudah tercakup test existing (delete happy path).

- [x] **Step 2: Jalankan, pastikan gagal**

Run: `vendor/bin/phpunit --filter="test_hapus_jadwal_dengan_trip_selesai_ditolak|test_hapus_jadwal_dengan_trip_berjalan_ditolak|test_ganti_shift_jadwal_dengan_trip_selesai_ditolak"`
Expected: 3 FAIL (delete/update sekarang 200).

- [x] **Step 3: Implementasi helper + guard**

Di `JadwalShiftService`, tambah helper privat (dekat `delete()`):

```php
    private function statusTripUntukJadwal(object $record): ?string
    {
        $tanggal = (string) $record->tanggal;
        $map = $this->tripRepo->statusTripPerSupirTanggal(
            (string) $record->id_proyek,
            [(string) $record->id_supir],
            $tanggal,
            $tanggal,
        );

        return $map["{$record->id_supir}|{$tanggal}"]['status'] ?? null;
    }

    private function labelStatusTrip(string $status): string
    {
        return $status === 'selesai' ? 'sudah selesai' : 'sedang berjalan';
    }
```

Di `updateShift()`, setelah `findOrFail`:

```php
        $statusTrip = $this->statusTripUntukJadwal($record);
        if ($statusTrip !== null) {
            abort(422, "Jadwal tanggal {$record->tanggal} tidak dapat diganti shift-nya — trip supir pada tanggal ini " . $this->labelStatusTrip($statusTrip));
        }
```

Di `delete()`, setelah `findOrFail` (sebelum guard trip-aktif lama):

```php
        $statusTrip = $this->statusTripUntukJadwal($record);
        if ($statusTrip !== null) {
            abort(422, "Jadwal tanggal {$record->tanggal} tidak dapat dihapus — trip supir pada tanggal ini " . $this->labelStatusTrip($statusTrip));
        }
```

- [x] **Step 4: Jalankan 3 test → PASS; lalu `--filter=JadwalShiftTest` → semua PASS**

---

### Task 2: Guard import timpa + suite penuh

**Files:**
- Modify: `TMN-TRANSPORT-BACKEND/app/Modules/JadwalShift/JadwalShiftService.php` (`importMatriks`, cabang timpa)
- Test: `TMN-TRANSPORT-BACKEND/tests/Feature/JadwalShiftImportTest.php` (1 test baru; helper `makeJadwal` + model trip sudah di-import di file itu? cek — bila belum, tambah `use` `JadwalKeberangkatanModel`/`TripModel`)

**Interfaces:**
- Consumes: `statusTripUntukJadwal(object $record): ?string` + `labelStatusTrip(string): string` dari Task 1. Catatan: `$ada` dari `findAktifBySupirTanggal` punya `id_proyek`, `id_supir`, `tanggal` — cukup untuk helper.
- Produces: — (terminal).

- [x] **Step 1: Tulis failing test**

```php
    public function test_import_tidak_menimpa_jadwal_dengan_trip_selesai(): void
    {
        $this->actingAsRole('SUPERADMIN');
        $proyek = $this->makeProyek();
        $idSupir = $this->makeSupir('Supir Terkunci', 'SIM-LOCK-1');
        $penugasan = PenugasanModel::create([
            'id_perusahaan' => self::PERUSAHAAN_ID,
            'id_proyek'     => $proyek->id_proyek,
            'id_supir'      => $idSupir,
            'status'        => 'aktif',
            'tanggal_tugas' => now()->toDateString(),
        ]);
        $idShiftPagi = $this->makeShiftNamed('Pagi');
        $this->makeShiftNamed('Malam');
        $this->makeJadwal($proyek->id_proyek, $idShiftPagi, $idSupir, '2026-08-10');

        $jk = \App\Modules\JadwalKeberangkatan\JadwalKeberangkatanModel::create([
            'id_penugasan' => $penugasan->id_penugasan, 'waktu_berangkat' => '2026-08-10 08:00:00',
        ]);
        \App\Modules\Trip\TripModel::create([
            'id_jadwal' => $jk->id_jadwal, 'status' => 'selesai',
            'waktu_checkin' => '2026-08-10 08:05:00', 'waktu_checkout' => '2026-08-10 17:00:00',
        ]);

        $file = $this->buatFileMatriks([
            ['No SIM', 'Nama Supir', 'Shift', '2026-08-10'],
            ['SIM-LOCK-1', 'Supir Terkunci', 'Malam', 'H'],
        ]);

        $res = $this->post('/api/v1/jadwal-shift/import', [
            'id_proyek' => $proyek->id_proyek,
            'file'      => $file,
        ]);
        $res->assertStatus(200)->assertJsonPath('data.sukses', 0);
        $this->assertStringContainsString('sudah selesai', (string) $res->json('data.gagal.0.alasan'));
        $this->assertDatabaseHas('jadwal_shift', [
            'id_supir' => $idSupir, 'tanggal' => '2026-08-10', 'id_shift' => $idShiftPagi, 'dihapus_pada' => null,
        ]);
    }
```

- [x] **Step 2: Jalankan → FAIL (sekarang ditimpa, sukses 1)**

Run: `vendor/bin/phpunit --filter=test_import_tidak_menimpa_jadwal_dengan_trip_selesai`

- [x] **Step 3: Implementasi guard di cabang timpa**

Di `importMatriks`, di dalam blok `if ($ada !== null) {`, SETELAH cek shift sama (`$ada->id_shift === $shift->id_shift`) dan SEBELUM cek trip-aktif hari ini:

```php
                        $statusTrip = $this->statusTripUntukJadwal($ada);
                        if ($statusTrip !== null) {
                            $gagal[] = ['baris' => $barisKe, 'no_sim' => $noSim, 'alasan' => "Tanggal {$tanggal}: trip supir " . $this->labelStatusTrip($statusTrip) . ' — jadwal tidak ditimpa'];
                            continue;
                        }
```

- [x] **Step 4: Jalankan test → PASS; `--filter=JadwalShiftImportTest` → semua PASS**

- [x] **Step 5: Suite penuh**

Run: `vendor/bin/phpunit`
Expected: semua PASS. JANGAN commit.

---

## Verifikasi Manual (oleh user)

1. Papan jadwal: sel dengan badge trip selesai/berjalan → hapus ditolak dengan pesan jelas; bulk delete melewati sel itu (muncul di dialog gagal).
2. Ganti shift sel ber-trip → ditolak.
3. Import file yang mengubah shift hari ber-trip → baris masuk daftar gagal, papan tidak berubah.
