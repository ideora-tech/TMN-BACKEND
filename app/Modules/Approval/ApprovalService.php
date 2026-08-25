<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use Illuminate\Support\Collection;

class ApprovalService
{
    public function __construct(private readonly ApprovalRepositoryInterface $repo) {}

    public function listEventType(string $idPerusahaan): Collection
    {
        return $this->repo->listEventType($idPerusahaan);
    }

    public function createEventType(array $data, string $idPerusahaan): ApprovalEventTypeModel
    {
        return $this->repo->createEventType([
            ...$data,
            'id_perusahaan' => $idPerusahaan,
            'aktif'         => 1,
        ]);
    }

    public function updateEventType(string $id, array $data, string $idPerusahaan): ApprovalEventTypeModel
    {
        $record = $this->repo->findEventTypeOrFail($id, $idPerusahaan);
        return $this->repo->updateEventType($record, $data);
    }

    public function listConfigApprover(string $idEventType, string $idPerusahaan): array
    {
        $this->repo->findEventTypeOrFail($idEventType, $idPerusahaan);
        return $this->repo->listConfigApprover($idEventType);
    }

    public function tambahConfigApprover(string $idEventType, array $data, string $idPerusahaan): string
    {
        $this->repo->findEventTypeOrFail($idEventType, $idPerusahaan);
        return $this->repo->insertConfigApprover([...$data, 'id_event_type' => $idEventType]);
    }

    public function hapusConfigApprover(string $idEventType, string $idConfig, string $idPerusahaan): void
    {
        $this->repo->findEventTypeOrFail($idEventType, $idPerusahaan);
        if (!$this->repo->deleteConfigApprover($idConfig, $idEventType)) {
            abort(404, 'Config approver tidak ditemukan');
        }
    }
}
