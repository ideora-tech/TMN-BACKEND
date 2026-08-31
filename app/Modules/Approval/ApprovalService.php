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
        $existing = $this->repo->findEventTypeAktifByKode($data['kode'], $idPerusahaan);
        if ($existing !== null) {
            abort(409, "Jenis pengajuan dengan kode '{$data['kode']}' sudah ada");
        }

        return $this->repo->createEventType([
            ...$data,
            'id_perusahaan' => $idPerusahaan,
            'aktif'         => 1,
        ]);
    }

    public function updateEventType(string $id, array $data, string $idPerusahaan): ApprovalEventTypeModel
    {
        $record = $this->repo->findEventTypeOrFail($id, $idPerusahaan);

        $mengaktifkanKembali = array_key_exists('aktif', $data) && (bool) $data['aktif'] && !(bool) $record->aktif;
        if ($mengaktifkanKembali) {
            $existing = $this->repo->findEventTypeAktifByKode($record->kode, $idPerusahaan);
            if ($existing !== null && $existing->id_event_type !== $record->id_event_type) {
                abort(409, 'Jenis pengajuan dengan kode ini sudah aktif');
            }
        }

        return $this->repo->updateEventType($record, $data);
    }

    public function hapusEventType(string $id, string $idPerusahaan): void
    {
        $record = $this->repo->findEventTypeOrFail($id, $idPerusahaan);

        if ($this->repo->adaRiwayatPengajuanUntukEventType($record->id_event_type)) {
            abort(422, 'Jenis pengajuan sudah memiliki riwayat approval — nonaktifkan saja, jangan dihapus');
        }

        DB::transaction(function () use ($record) {
            $this->repo->deleteConfigApproverByEventType($record->id_event_type);
            $this->repo->deleteEventType($record->id_event_type);
        });
    }

    public function listConfigApprover(string $idEventType, string $idPerusahaan): array
    {
        $this->repo->findEventTypeOrFail($idEventType, $idPerusahaan);
        return $this->repo->listConfigApprover($idEventType, $idPerusahaan);
    }

    public function tambahConfigApprover(string $idEventType, array $data, string $idPerusahaan): string
    {
        $this->repo->findEventTypeOrFail($idEventType, $idPerusahaan);
        $this->pastikanMilikPerusahaan($data, $idPerusahaan);

        if ($this->repo->adaConfigApproverAktif($idEventType, $data['tipe'], $data['id_jabatan'] ?? null, $data['id_pengguna'] ?? null)) {
            abort(409, 'Approver ini sudah terdaftar di jenis pengajuan ini');
        }

        return $this->repo->insertConfigApprover([...$data, 'id_event_type' => $idEventType]);
    }

    private function pastikanMilikPerusahaan(array $data, string $idPerusahaan): void
    {
        if ($data['tipe'] === 'jabatan') {
            if (!$this->repo->jabatanMilikPerusahaan($data['id_jabatan'], $idPerusahaan)) {
                abort(404, 'Jabatan tidak ditemukan');
            }
            return;
        }

        if ($data['tipe'] === 'pengguna') {
            if (!$this->repo->penggunaMilikPerusahaan($data['id_pengguna'], $idPerusahaan)) {
                abort(404, 'Pengguna tidak ditemukan');
            }
        }
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

    public function riwayatApprovalSaya(string $idPengguna, string $idPerusahaan): Collection
    {
        return $this->repo->listRiwayatApprovalSaya($idPengguna, $idPerusahaan);
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
                $this->selesaikanKeputusan($pengajuan, $eventType, 'ditolak', $catatan, $idPengguna);
                return $pengajuan;
            }

            if ($this->repo->hitungKeputusanBelumSetuju($idApproval) === 0) {
                $pengajuan = $this->repo->updatePengajuan($pengajuan, ['status' => 'disetujui']);
                $this->selesaikanKeputusan($pengajuan, $eventType, 'disetujui', null, $idPengguna);
            }

            return $pengajuan->fresh();
        });
    }

    public function putuskanUntukReferensi(string $kodeEventType, string $idReferensi, string $idPengguna, string $keputusan, ?string $catatan, string $idPerusahaan): ApprovalPengajuanModel
    {
        $eventType = $this->repo->findEventTypeAktifByKode($kodeEventType, $idPerusahaan);
        if ($eventType === null) {
            abort(404, "Event type approval '{$kodeEventType}' tidak ditemukan");
        }

        $pengajuan = $this->repo->findPengajuanAktifUntukReferensi($eventType->id_event_type, $idReferensi);
        if ($pengajuan === null) {
            abort(404, 'Pengajuan approval untuk referensi ini tidak ditemukan');
        }

        return $this->putuskan($pengajuan->id_approval, $idPengguna, $keputusan, $catatan, $idPerusahaan);
    }

    public function statusUntukReferensi(string $kode, string $idReferensi, string $idPerusahaan): ?array
    {
        return $this->repo->statusUntukReferensi($kode, $idReferensi, $idPerusahaan);
    }

    public function adaEventTypeAktif(string $kode, string $idPerusahaan): bool
    {
        return $this->repo->findEventTypeAktifByKode($kode, $idPerusahaan) !== null;
    }

    public function batalkanUntukReferensi(array $kodeList, string $idReferensi, string $idPerusahaan): void
    {
        foreach ($kodeList as $kode) {
            $eventType = $this->repo->findEventTypeByKode($kode, $idPerusahaan);
            if ($eventType === null) {
                continue;
            }

            DB::transaction(function () use ($eventType, $idReferensi, $idPerusahaan) {
                $aktif = $this->repo->findPengajuanAktifUntukReferensiForUpdate($eventType->id_event_type, $idReferensi, $idPerusahaan);
                if ($aktif !== null && in_array($aktif->status, ['menunggu', 'disetujui'], true)) {
                    $this->repo->voidKeputusanUntukApproval($aktif->id_approval);
                    $this->repo->updatePengajuan($aktif, ['status' => 'dibatalkan']);
                }
            });
        }
    }

    public function batalkanDanAjukanUlang(string $kodeEventType, string $idReferensi, string $idPenggunaPengaju, ?float $nominalBaru, string $idPerusahaan): ApprovalPengajuanModel
    {
        $eventType = $this->repo->findEventTypeAktifByKode($kodeEventType, $idPerusahaan);
        if ($eventType === null) {
            abort(422, "Event type approval '{$kodeEventType}' belum dikonfigurasi");
        }

        DB::transaction(function () use ($eventType, $idReferensi, $idPerusahaan) {
            $aktif = $this->repo->findPengajuanAktifUntukReferensiForUpdate($eventType->id_event_type, $idReferensi, $idPerusahaan);
            if ($aktif !== null) {
                $this->repo->voidKeputusanUntukApproval($aktif->id_approval);
                $this->repo->updatePengajuan($aktif, ['status' => 'dibatalkan']);
            }
        });

        return $this->ajukan($kodeEventType, $idReferensi, $idPenggunaPengaju, $nominalBaru, $idPerusahaan);
    }

    private function selesaikanKeputusan(ApprovalPengajuanModel $pengajuan, ApprovalEventTypeModel $eventType, string $keputusan, ?string $alasanDitolak, string $idPengguna): void
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

        event(new \App\Events\ApprovalDiputuskan(
            $pengajuan->id_perusahaan,
            $pengajuan->id_approval,
            $idPengguna,
            $eventType->kode,
            $pengajuan->id_referensi,
            $keputusan,
            $alasanDitolak,
        ));
    }
}
