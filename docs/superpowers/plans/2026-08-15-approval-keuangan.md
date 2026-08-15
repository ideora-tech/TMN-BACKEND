# Approval Keuangan (BOD) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persetujuan pengajuan pengeluaran level BOD yang dinamis: approver dikonfigurasi (jabatan + orang), threshold nominal, semua approver wajib setuju, notifikasi in-app + FCM.

**Architecture:** Langkah `setujui` (role Manager, hardcoded) diganti tahap `menunggu_approval`: saat `cek()` lolos dan nominal >= batas, daftar approver di-snapshot ke `pengajuan_approval` (semua wajib setuju; satu tolak = ditolak). Konfigurasi di 2 tabel baru (`approver_keuangan`, `pengaturan` key-value). Hook sinkron pembelian pindah ke titik transisi disetujui/ditolak.

**Tech Stack:** Laravel 11 module ArusKas (+Notifikasi existing), Next.js 15 Ecme.

**Spec:** `docs/superpowers/specs/2026-08-15-approval-keuangan-design.md` — baca bagian yang dirujuk tiap task.

## Global Constraints

- **DILARANG `git commit`/mengubah git state** — user commit manual; akhir task = laporkan ringkasan diff.
- **DILARANG build** (npm/docker). `npm run lint` boleh. Test backend HANYA `vendor/bin/phpunit --filter=...`.
- Query DB hanya di *Repository.php; method repo baru WAJIB terdaftar di Contracts/*RepositoryInterface.php.
- Tabel baru wajib `MigrationHelper::auditColumns`; query aktif `whereNull('dihapus_pada')`; tanpa komentar penjelas; teks UI Indonesia.
- File FE `arus-kas/*` adalah WIP user — HANYA TAMBAHKAN bagian yang diminta, jangan merapikan/mengubah kode lain di file itu. Jika ada error lint pre-existing di file WIP (unused vars dialog), biarkan & catat.
- Status pengajuan: `diajukan|dicek|menunggu_approval|disetujui|ditolak|ditransfer` (varchar 20, tanpa migration enum).
- Konstanta baru di ArusKasService: `STATUS_MENUNGGU_APPROVAL = 'menunggu_approval'`; kunci pengaturan: `batas_approval_keuangan`.

---

### Task 1: Migration 3 tabel

**Files:**
- Create: `database/migrations/2026_08_15_100001_create_approver_keuangan_table.php`
- Create: `database/migrations/2026_08_15_100002_create_pengaturan_table.php`
- Create: `database/migrations/2026_08_15_100003_create_pengajuan_approval_table.php`

**Interfaces:** Produces tabel sesuai spec §4 (kolom persis tabel di spec).

- [ ] **Step 1:** Tulis 3 migration. Pola identik migration existing (`declare(strict_types=1)`, `MigrationHelper::auditColumns`, `Schema::dropIfExists` di down). Kolom per spec §4.1–4.3; `approver_keuangan.id_perusahaan` index; `pengaturan` index (id_perusahaan, kunci); `pengajuan_approval.id_pengajuan` index.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter=ArusKasPengajuanTest` — Expected PASS (bukti migration valid di sqlite).
- [ ] **Step 3:** Checkpoint diff. Jangan commit.

---

### Task 2: Backend konfigurasi — approver + batas nominal

**Files:**
- Modify: `app/Modules/ArusKas/ArusKasRepository.php` + `Contracts/ArusKasRepositoryInterface.php`
- Modify: `app/Modules/ArusKas/ArusKasService.php`, `ArusKasController.php`, `ArusKasServiceProvider.php`
- Test: `tests/Feature/ApprovalKeuanganKonfigTest.php` (baru; setup tiru `ArusKasPengajuanTest`)

**Interfaces (produces, dipakai Task 3-4):**
- Repo: `listApprover(string $idPerusahaan): array` (join jabatan.nama_jabatan & pengguna.username → field `nama` per baris), `insertApprover(array $data): void`, `softDeleteApprover(string $id, string $idPerusahaan): bool`, `adaApproverAktif(string $idPerusahaan, string $tipe, ?string $idRef): bool`, `getPengaturan(string $idPerusahaan, string $kunci): ?string`, `setPengaturan(string $idPerusahaan, string $kunci, string $nilai): void` (upsert), `resolusiApprover(string $idPerusahaan): array` (list id_pengguna unik: tipe pengguna langsung; tipe jabatan → pengguna aktif via pengguna.id_karyawan → karyawan.id_jabatan; semua whereNull dihapus_pada + aktif=1).
- Service: `listApprover`, `tambahApprover(array,idPerusahaan)` (validasi duplikat via `adaApproverAktif` → abort 409 'Approver sudah terdaftar'), `hapusApprover(id,idPerusahaan)` (404 bila tak ada), `batasApproval(idPerusahaan): float` (default 0), `setBatasApproval(idPerusahaan, float)`.
- Routes (dalam group `role:SUPERADMIN,ADMIN` baru): `GET/POST arus-kas/approver`, `DELETE arus-kas/approver/{id}`, `GET/PUT arus-kas/pengaturan-approval`.
- Controller validasi POST approver: `tipe in:jabatan,pengguna`; `id_jabatan required_if:tipe,jabatan`; `id_pengguna required_if:tipe,pengguna`. PUT pengaturan: `batas numeric|min:0`.

- [ ] **Step 1:** Tulis failing test: tambah approver jabatan & pengguna → GET berisi 2 baris dengan `nama` terisi; duplikat → 409; delete → hilang dari list; PUT batas 5000000 → GET mengembalikan 5000000; GET batas default → 0; role KEUANGAN akses POST approver → 403.
- [ ] **Step 2:** Run FAIL → implement → PASS (`vendor/bin/phpunit --filter=ApprovalKeuanganKonfigTest`).
- [ ] **Step 3:** Checkpoint diff.

---

### Task 3: Transisi cek() — threshold, snapshot, notifikasi

**Files:**
- Modify: `app/Modules/ArusKas/ArusKasService.php` (cek), `ArusKasRepository.php` + interface, `ArusKasServiceProvider.php` (inject NotifikasiService bila perlu via constructor service)
- Test: `tests/Feature/ApprovalKeuanganAlurTest.php` (baru)

**Interfaces (produces):**
- Repo: `insertApprovalRows(string $idPengajuan, array $idPenggunaList): void` (status 'menunggu', RecordHelper::stampCreate id 'id_approval'), `listApproval(string $idPengajuan): array` (join pengguna.username as nama, order dibuat_pada), `updateApprovalRow(string $idApproval, array $data): void`, `findApprovalMenunggu(string $idPengajuan, string $idPengguna): ?object`.
- Service `cek()` baru (ganti body): guard status diajukan (tetap) → set dicek+dicek_oleh/pada → lalu `masukTahapApproval($record)`:
```php
private function masukTahapApproval(PengajuanPengeluaranModel $record): PengajuanPengeluaranModel
{
    $batas = $this->batasApproval((string) $record->id_perusahaan);
    if ((float) $record->nominal < $batas) {
        $updated = $this->repo->updatePengajuan($record, ['status' => self::STATUS_DISETUJUI, 'disetujui_pada' => now()]);
        $this->jalankanHookSetujui($updated);
        return $updated;
    }

    $approvers = $this->repo->resolusiApprover((string) $record->id_perusahaan);
    if ($approvers === []) {
        abort(422, 'Approver keuangan belum dikonfigurasi — atur di Pengaturan → Approval Keuangan');
    }

    $this->repo->insertApprovalRows((string) $record->id_pengajuan, $approvers);
    $updated = $this->repo->updatePengajuan($record, ['status' => self::STATUS_MENUNGGU_APPROVAL]);
    foreach ($approvers as $idPengguna) {
        $this->notifikasiService->buatDanKirim([
            'id_perusahaan' => $record->id_perusahaan,
            'id_pengguna'   => $idPengguna,
            'judul'         => "Pengajuan {$record->nomor_pengajuan} menunggu approval Anda",
            'isi'           => 'Nominal Rp ' . number_format((float) $record->nominal, 0, ',', '.') . " — {$record->penerima} ({$record->kategori})",
            'tipe'          => 'approval_keuangan',
            'referensi_id'   => $record->id_pengajuan,
            'referensi_tipe' => 'pengajuan_pengeluaran',
            'dibaca'         => 0,
        ]);
    }
    return $updated;
}
```
- `jalankanHookSetujui($record)`: berisi pemanggilan `sinkronPembelianSetujui` bila `id_pembelian` (dipindah dari setujui() lama). Ekstrak juga `jalankanHookTolak` dari tolak() lama (sinkronPembelianTolak) untuk dipakai Task 4.
- Semua dalam `DB::transaction` di cek().

- [ ] **Step 1:** Failing test: (a) batas 0 + 2 approver (1 jabatan ber-2 pengguna? cukup 2 pengguna langsung) → cek() → status menunggu_approval + 2 baris menunggu + 2 notifikasi di tabel notifikasi; (b) batas 1.000.000, nominal 500rb → cek() → langsung disetujui, tanpa baris approval; (c) tanpa approver + nominal >= batas → cek() 422 dan status TETAP diajukan (transaksi rollback); (d) resolusi jabatan: buat karyawan+pengguna berjabatan X, approver tipe jabatan X → baris approval untuk pengguna itu; (e) dedup: pengguna sama dari jabatan + ditunjuk langsung → 1 baris.
- [ ] **Step 2:** FAIL → implement → PASS. Regression `vendor/bin/phpunit --filter="ArusKasPengajuanTest|ArusKasOtomatisTest|ArusKasSparepartTest"` — sesuaikan test lama yang meng-assert alur setujui lama HANYA bila memang berubah perilaku (jelaskan di laporan).
- [ ] **Step 3:** Checkpoint diff.

---

### Task 4: Endpoint approval (setuju/tolak) + hapus setujui lama

**Files:**
- Modify: `app/Modules/ArusKas/ArusKasService.php`, `ArusKasController.php`, `ArusKasServiceProvider.php`
- Test: tambah di `tests/Feature/ApprovalKeuanganAlurTest.php`

**Interfaces (produces):**
- Route BARU (tanpa role middleware; guard service): `PATCH arus-kas/pengajuan/{id}/approval` body `{keputusan: in:setuju,tolak; catatan: required_if:keputusan,tolak|max:255}`.
- Route `PATCH arus-kas/pengajuan/{id}/setujui` + method controller `setujuiPengajuan` + service `setujui()` DIHAPUS (grup `role:SUPERADMIN,MANAGER` ikut hilang bila kosong).
- Service `prosesApproval(string $id, string $keputusan, ?string $catatan, string $idPengguna, string $idPerusahaan)`:
  - findPengajuanOrFail; bila status `dicek` → jalankan `masukTahapApproval` dulu (lazy, spec §9); bila hasilnya langsung disetujui → return dengan pesan info.
  - Guard status `menunggu_approval`; `findApprovalMenunggu(id, idPengguna)` null → sudah beraksi? cek baris user ada tapi bukan menunggu → 409 'Anda sudah memberikan keputusan'; tidak ada baris sama sekali → 403 'Anda bukan approver pengajuan ini'.
  - setuju: updateApprovalRow(status disetujui, waktu_aksi now); bila tidak ada lagi baris menunggu → pengajuan disetujui + disetujui_oleh=idPengguna + disetujui_pada + `jalankanHookSetujui`.
  - tolak: updateApprovalRow(status ditolak, catatan, waktu_aksi); pengajuan → ditolak + alasan_ditolak=catatan + `jalankanHookTolak`.
  - Semua dalam DB::transaction.

- [ ] **Step 1:** Failing test: approve 1 dari 2 → status tetap menunggu_approval; approve kedua → disetujui + disetujui_oleh = approver kedua; tolak oleh satu approver (catatan) → pengajuan ditolak + alasan; tolak tanpa catatan → 422; non-approver → 403; approve dua kali → 409; pengajuan status `dicek` lama → PATCH approval oleh approver → snapshot terbentuk + keputusan diproses; transfer dari disetujui hasil approval → 200 (regresi); pembelian sparepart: pengajuan pembelian melalui approval penuh → status pembelian tersinkron `disetujui_finance`.
- [ ] **Step 2:** FAIL → implement → PASS. Regression penuh `vendor/bin/phpunit` — test lama yang memakai endpoint setujui lama (mis. ArusKasPengajuanTest/ArusKasSparepartTest/ArusKasPerawatanTest/ArusKasPayrollTest) DISESUAIKAN ke alur baru: set batas besar (auto-disetujui) ATAU daftarkan approver + PATCH approval; pilih yang paling kecil perubahannya per test, jelaskan di laporan.
- [ ] **Step 3:** Checkpoint diff.

---

### Task 5: Respons approval + log trip

**Files:**
- Modify: `app/Modules/ArusKas/Resources/PengajuanPengeluaranResource.php` (atau resource yang dipakai index/show pengajuan)
- Modify: `app/Modules/ArusKas/ArusKasController.php` (attach data di index/show), `ArusKasService.php` (`infoPengajuanTrip` + helper attach)
- Test: tambah di `ApprovalKeuanganAlurTest`

**Interfaces (produces, dipakai FE Task 6-7):**
- Respons pengajuan (show + index): `approval: [{id_pengguna, nama, status, catatan, waktu_aksi}]`, `approval_progress: {disetujui: n, total: m}` (null bila tak ada baris), `bisa_approve: bool` (user login punya baris menunggu; di index hitung bulk tanpa N+1 — satu query listApproval per halaman via whereIn id_pengajuan, group di service).
- `infoPengajuanTrip` (log uang jalan di detail trip): tambah entri riwayat per baris approval beraksi: status `disetujui`/`ditolak` per approver (`oleh`=nama, `waktu`=waktu_aksi, keterangan=catatan) disisipkan urut waktu; status pengajuan `menunggu_approval` ikut dikembalikan di field `status`.

- [ ] **Step 1:** Failing test: show pengajuan menunggu → approval array 2 baris + progress {0,2} + bisa_approve true utk approver / false utk lainnya; setelah 1 approve → progress {1,2}; GET /trip/{id} untuk trip ber-pengajuan menunggu → `pengajuan_uang_jalan.status = menunggu_approval` dan riwayat memuat entri approval setelah aksi.
- [ ] **Step 2:** FAIL → implement → PASS + regression `vendor/bin/phpunit --filter="ArusKas|TripTitikDrop"`.
- [ ] **Step 3:** Checkpoint diff.

---

### Task 6: FE — Pengaturan → Approval Keuangan

**Files:**
- Create: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/approval-keuangan/page.tsx`
- Create: `TMN-TRANSPORT-FRONTEND/src/services/approvalKeuangan.service.ts`
- Modify: `TMN-TRANSPORT-FRONTEND/src/constants/api.constant.ts` (APPROVER_KEUANGAN, APPROVER_KEUANGAN_DETAIL, PENGATURAN_APPROVAL), `route.constant.ts`
- Create migration seed menu: `database/migrations/2026_08_15_100004_seed_menu_approval_keuangan.php` (pola file seed_menu_* terbaru; parent grup Pengaturan)

**Interfaces:** Consumes API Task 2.

- [ ] **Step 1:** Service TS: `list()`, `tambah(payload {tipe, id_jabatan?, id_pengguna?})`, `hapus(id)`, `getBatas()`, `setBatas(nilai:number)` + tipe `ApproverKeuangan {id_approver, tipe, nama, id_jabatan|null, id_pengguna|null}`.
- [ ] **Step 2:** Halaman (pola standar list modul): judul "Approval Keuangan" + subtitle; Card pertama: form "Batas Nominal Approval" (Input prefix Rp + tombol Simpan + teks bantu "0 = semua pengajuan wajib approval BOD"); Card kedua: tabel approver (No, Tipe [Tag jabatan=biru/pengguna=ungu], Nama, aksi hapus dgn ConfirmDialog) + tombol "Tambah Approver" (HiPlusCircle, kanan judul) → Dialog: Select tipe → Select jabatan (dari jabatanService existing) ATAU Select pengguna (penggunaService existing — cek nama service di src/services), simpan → refresh. Seed menu mengarah ke /approval-keuangan di bawah grup Pengaturan (tiru struktur migration seed menu yang ada, termasuk izin peran SUPERADMIN/ADMIN).
- [ ] **Step 3:** `npm run lint` bersih (abaikan error pre-existing file WIP user, catat). Checkpoint diff.

---

### Task 7: FE — Arus Kas & detail trip

**Files:**
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/PengajuanTab.tsx` (WIP user — hanya menambah)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/arus-kas/DetailPengajuanDialog.tsx` (WIP user — hanya menambah)
- Modify: service pengajuan arus kas TS (cari di src/services, tambah tipe approval fields + method `keputusanApproval(id, keputusan, catatan?)` → PATCH APPROVAL endpoint baru di api.constant.ts)
- Modify: `TMN-TRANSPORT-FRONTEND/src/app/(protected-pages)/trip/[id]/page.tsx` (PENGAJUAN_LABEL/TAG/BORDER + label menunggu_approval amber)

**Interfaces:** Consumes respons Task 5.

- [ ] **Step 1:** PengajuanTab: badge status `menunggu_approval` ("Menunggu Approval", amber) + di sel status tampilkan progress kecil `N/M approve` bila `approval_progress` ada.
- [ ] **Step 2:** DetailPengajuanDialog: blok "Approval BOD" — daftar approver (nama + badge status + catatan + waktu); bila `bisa_approve` → tombol **Approve** (solid) & **Tolak** (merah) → Tolak buka ConfirmDialog dgn textarea catatan wajib; sukses → toast + refresh list/dialog.
- [ ] **Step 3:** trip/[id]: tambah `menunggu_approval: 'Menunggu Approval'` (label), tag amber, border amber di ketiga peta konstanta pengajuan.
- [ ] **Step 4:** `npm run lint` tanpa error baru. Checkpoint diff + instruksi uji manual end-to-end untuk user (set approver → pengajuan uang jalan → cek → notif → approve semua → transfer; kasus tolak; kasus di bawah batas).
