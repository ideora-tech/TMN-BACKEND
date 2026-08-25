<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use Illuminate\Support\Collection;

class ApprovalService
{
    public function __construct(
        private readonly ApprovalRepositoryInterface $repo,
        private readonly ApprovalResolverService $resolver,
        private readonly \App\Modules\Notifikasi\NotifikasiService $notifikasiService,
    ) {}

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

    public function ajukan(
        string $kodeEventType,
        string $idReferensi,
        string $idPenggunaPengaju,
        ?float $nominal,
        string $idPerusahaan,
    ): ApprovalPengajuanModel {
        $eventType = $this->repo->findEventTypeAktifByKode($kodeEventType, $idPerusahaan);
        if ($eventType === null) {
            abort(422, "Event type approval '{$kodeEventType}' belum dikonfigurasi");
        }

        $approvers = $this->resolver->resolve($eventType, $idPenggunaPengaju);
        if ($approvers === []) {
            abort(422, 'Approver untuk proses ini belum bisa ditentukan — struktur organisasi/konfigurasi approval belum lengkap');
        }

        $pengajuan = $this->repo->createPengajuan([
            'id_perusahaan'       => $idPerusahaan,
            'id_event_type'       => $eventType->id_event_type,
            'id_referensi'        => $idReferensi,
            'id_pengguna_pengaju' => $idPenggunaPengaju,
            'nominal'             => $nominal,
            'status'              => 'menunggu',
        ]);

        $this->repo->insertKeputusanRows($pengajuan->id_approval, $approvers);

        foreach ($approvers as $idPengguna) {
            $this->notifikasiService->buatDanKirim([
                'id_perusahaan'  => $idPerusahaan,
                'id_pengguna'    => $idPengguna,
                'judul'          => "Approval {$eventType->nama} menunggu keputusan Anda",
                'isi'            => $nominal !== null ? 'Nominal Rp ' . number_format($nominal, 0, ',', '.') : 'Perlu keputusan Anda',
                'tipe'           => 'approval_generik',
                'referensi_id'   => $pengajuan->id_approval,
                'referensi_tipe' => 'approval_pengajuan',
                'dibaca'         => 0,
            ]);
        }

        return $pengajuan;
    }
}
