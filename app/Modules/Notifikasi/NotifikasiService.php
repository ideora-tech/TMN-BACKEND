<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Modules\Notifikasi\Contracts\NotifikasiRepositoryInterface;
use App\Support\PengirimPush;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotifikasiService
{
    public function __construct(
        private readonly NotifikasiRepositoryInterface $repo,
        private readonly PengirimPush $push,
    ) {}

    public function list(string $idPengguna, string $idPerusahaan, int $page = 1, int $limit = 20, ?string $tipe = null, ?int $dibaca = null): array
    {
        $paginator = $this->repo->paginateForUser($idPengguna, $idPerusahaan, $page, $limit, $tipe, $dibaca);
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'        => $paginator->currentPage(),
                'limit'       => $paginator->perPage(),
                'total'       => $paginator->total(),
                'totalPages'  => $paginator->lastPage(),
                'unreadCount' => $this->repo->unreadCount($idPengguna, $idPerusahaan),
            ],
        ];
    }

    public function findOrFail(string $id): NotifikasiModel
    {
        $n = $this->repo->findById($id);
        if (!$n) {
            abort(404, 'Notifikasi tidak ditemukan');
        }
        return $n;
    }

    public function create(array $data): NotifikasiModel
    {
        $data['id_notifikasi'] = Str::uuid()->toString();
        return $this->repo->create($data);
    }

    public function buatDanKirim(array $data): NotifikasiModel
    {
        $record = $this->create($data);

        if (!empty($data['id_pengguna'])) {
            $this->push->kirimKePengguna(
                (string) $data['id_pengguna'],
                (string) $data['judul'],
                (string) $data['isi'],
                [
                    'referensi_tipe' => (string) ($data['referensi_tipe'] ?? ''),
                    'referensi_id'   => (string) ($data['referensi_id'] ?? ''),
                ],
            );
        }

        return $record;
    }

    public function kirimKeSupir(string $idSupir, string $idPerusahaan, string $judul, string $isi, string $tipe, string $referensiTipe, string $referensiId): void
    {
        $idPengguna = $this->repo->idPenggunaUntukSupir($idSupir);
        if ($idPengguna === null || $idPengguna === '') {
            Log::info("Notifikasi supir dilewati: supir {$idSupir} tidak punya akun pengguna");
            return;
        }

        $this->buatDanKirim([
            'id_perusahaan'  => $idPerusahaan,
            'id_pengguna'    => $idPengguna,
            'judul'          => $judul,
            'isi'            => $isi,
            'tipe'           => $tipe,
            'referensi_id'   => $referensiId,
            'referensi_tipe' => $referensiTipe,
            'dibaca'         => 0,
        ]);
    }

    public function kirimKeSupirVendor(string $idSupirVendor, string $idPerusahaan, string $judul, string $isi, string $tipe, string $referensiTipe, string $referensiId): void
    {
        $idPengguna = $this->repo->idPenggunaUntukSupirVendor($idSupirVendor);
        if ($idPengguna === null || $idPengguna === '') {
            return;
        }

        $this->buatDanKirim([
            'id_perusahaan'  => $idPerusahaan,
            'id_pengguna'    => $idPengguna,
            'judul'          => $judul,
            'isi'            => $isi,
            'tipe'           => $tipe,
            'referensi_id'   => $referensiId,
            'referensi_tipe' => $referensiTipe,
            'dibaca'         => 0,
        ]);
    }

    public function markRead(string $id): NotifikasiModel
    {
        return $this->repo->markRead($this->findOrFail($id));
    }

    public function markAllRead(string $idPengguna, string $idPerusahaan): int
    {
        return $this->repo->markAllRead($idPengguna, $idPerusahaan);
    }

    public function unreadCount(string $idPengguna, string $idPerusahaan): int
    {
        return $this->repo->unreadCount($idPengguna, $idPerusahaan);
    }
}