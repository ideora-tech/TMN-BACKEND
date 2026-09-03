<?php

declare(strict_types=1);

namespace App\Modules\Approval\Contracts;

use App\Modules\Approval\ApprovalEventTypeModel;
use App\Modules\Approval\ApprovalPengajuanModel;
use Illuminate\Support\Collection;

interface ApprovalRepositoryInterface
{
    public function listEventType(string $idPerusahaan): Collection;
    public function findEventTypeOrFail(string $id, string $idPerusahaan): ApprovalEventTypeModel;
    public function findEventTypeAktifByKode(string $kode, string $idPerusahaan): ?ApprovalEventTypeModel;
    public function findEventTypeByKode(string $kode, string $idPerusahaan): ?ApprovalEventTypeModel;
    public function createEventType(array $data): ApprovalEventTypeModel;
    public function updateEventType(ApprovalEventTypeModel $model, array $data): ApprovalEventTypeModel;
    public function adaRiwayatPengajuanUntukEventType(string $idEventType): bool;
    public function deleteEventType(string $idEventType): void;

    public function listConfigApprover(string $idEventType, string $idPerusahaan): array;
    public function adaConfigApproverAktif(string $idEventType, string $tipe, ?string $idJabatan, ?string $idPengguna): bool;
    public function insertConfigApprover(array $data): string;
    public function deleteConfigApprover(string $id, string $idEventType): bool;
    public function deleteConfigApproverByEventType(string $idEventType): void;

    public function resolvePinned(string $idEventType, string $idPerusahaan): array;
    public function cariJabatanPengguna(string $idPengguna, string $idPerusahaan): ?object;
    public function cariJabatanInduk(string $idJabatan): ?object;
    public function cariUserAktifPemegangJabatan(string $idJabatan, string $idPerusahaan): array;
    public function jabatanMilikPerusahaan(string $idJabatan, string $idPerusahaan): bool;
    public function penggunaMilikPerusahaan(string $idPengguna, string $idPerusahaan): bool;

    public function createPengajuan(array $data): ApprovalPengajuanModel;
    public function insertKeputusanRows(string $idApproval, array $idPenggunaList): void;
    public function findPengajuanForUpdate(string $id, string $idPerusahaan): ?ApprovalPengajuanModel;
    public function findKeputusanMenunggu(string $idApproval, string $idPengguna): ?object;
    public function updateKeputusanJikaMenunggu(string $idKeputusan, array $data): int;
    public function hitungKeputusanBelumSetuju(string $idApproval): int;
    public function updatePengajuan(ApprovalPengajuanModel $model, array $data): ApprovalPengajuanModel;
    public function findPengajuanAktifUntukReferensi(string $idEventType, string $idReferensi): ?ApprovalPengajuanModel;
    public function findPengajuanAktifUntukReferensiForUpdate(string $idEventType, string $idReferensi, string $idPerusahaan): ?ApprovalPengajuanModel;
    public function progressApproval(string $idApproval): array;
    public function statusUntukReferensi(string $kode, string $idReferensi, string $idPerusahaan): ?array;
    public function findPengajuanMenungguUntukReferensi(string $kode, string $idReferensi, string $idPerusahaan): ?ApprovalPengajuanModel;
    public function tambahLampiran(string $idApproval, array $lampiranList): void;
    public function lampiranUntukApproval(string $idApproval): array;
    public function listMenungguApprovalSaya(string $idPengguna, string $idPerusahaan): Collection;
    public function listRiwayatApprovalSaya(string $idPengguna, string $idPerusahaan): Collection;
    public function voidKeputusanUntukApproval(string $idApproval): void;
}
