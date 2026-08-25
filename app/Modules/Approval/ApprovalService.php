<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($eventType, $idReferensi, $idPenggunaPengaju, $nominal, $idPerusahaan, $approvers) {
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
        });
    }

    public function statusUntuk(string $kodeEventType, string $idReferensi, string $idPerusahaan): ?array
    {
        $eventType = $this->repo->findEventTypeAktifByKode($kodeEventType, $idPerusahaan);
        if ($eventType === null) {
            return null;
        }

        $pengajuan = $this->repo->findPengajuanAktifUntukReferensi($eventType->id_event_type, $idReferensi);
        if ($pengajuan === null) {
            return null;
        }

        return [
            'status'            => $pengajuan->status,
            'alasan_ditolak'    => $pengajuan->alasan_ditolak,
            'approval_progress' => $this->repo->progressApproval($pengajuan->id_approval),
        ];
    }

    public function menungguApprovalSaya(string $idPengguna, string $idPerusahaan): Collection
    {
        return $this->repo->listMenungguApprovalSaya($idPengguna, $idPerusahaan);
    }

    public function putuskan(string $idApproval, string $idPengguna, string $keputusan, ?string $catatan, string $idPerusahaan): ApprovalPengajuanModel
    {
        return DB::transaction(function () use ($idApproval, $idPengguna, $keputusan, $catatan, $idPerusahaan) {
            $pengajuan = $this->repo->findPengajuanForUpdate($idApproval, $idPerusahaan);
            if ($pengajuan === null) {
                abort(404, 'Pengajuan approval tidak ditemukan');
            }

            if ($pengajuan->status !== 'menunggu') {
                abort(409, 'Pengajuan ini sudah diputuskan');
            }

            $baris = $this->repo->findKeputusanMenunggu($idApproval, $idPengguna);
            if ($baris === null) {
                $sudahPernah = DB::table('approval_keputusan')
                    ->where('id_approval', $idApproval)
                    ->where('id_pengguna', $idPengguna)
                    ->exists();
                abort($sudahPernah ? 409 : 403, $sudahPernah ? 'Anda sudah memberikan keputusan' : 'Anda bukan approver pengajuan ini');
            }

            $statusBaris = $keputusan === 'setuju' ? 'disetujui' : 'ditolak';
            $terupdate = $this->repo->updateKeputusanJikaMenunggu($baris->id_keputusan, [
                'status'     => $statusBaris,
                'catatan'    => $catatan,
                'waktu_aksi' => now(),
            ]);
            if ($terupdate === 0) {
                abort(409, 'Keputusan sudah diproses');
            }

            $eventType = ApprovalEventTypeModel::active()->find($pengajuan->id_event_type);

            if ($keputusan === 'tolak') {
                $pengajuan = $this->repo->updatePengajuan($pengajuan, [
                    'status'         => 'ditolak',
                    'alasan_ditolak' => $catatan,
                ]);
                $this->selesaikanKeputusan($pengajuan, $eventType, 'ditolak', $catatan);
                return $pengajuan;
            }

            if ($this->repo->hitungKeputusanBelumSetuju($idApproval) === 0) {
                $pengajuan = $this->repo->updatePengajuan($pengajuan, ['status' => 'disetujui']);
                $this->selesaikanKeputusan($pengajuan, $eventType, 'disetujui', null);
            }

            return $pengajuan->fresh();
        });
    }

    private function selesaikanKeputusan(ApprovalPengajuanModel $pengajuan, ApprovalEventTypeModel $eventType, string $keputusan, ?string $alasanDitolak): void
    {
        $this->notifikasiService->buatDanKirim([
            'id_perusahaan'  => $pengajuan->id_perusahaan,
            'id_pengguna'    => $pengajuan->id_pengguna_pengaju,
            'judul'          => "Approval {$eventType->nama} Anda: " . ($keputusan === 'disetujui' ? 'Disetujui' : 'Ditolak'),
            'isi'            => $alasanDitolak ?? 'Pengajuan Anda telah diputuskan',
            'tipe'           => 'approval_generik',
            'referensi_id'   => $pengajuan->id_approval,
            'referensi_tipe' => 'approval_pengajuan',
            'dibaca'         => 0,
        ]);

        event(new \App\Events\ApprovalDiputuskan($eventType->kode, $pengajuan->id_referensi, $keputusan, $alasanDitolak));
    }
}
