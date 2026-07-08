<?php

declare(strict_types=1);

namespace App\Modules\Notifikasi;

use App\Modules\Notifikasi\Contracts\NotifikasiRepositoryInterface;
use Illuminate\Support\Str;

class NotifikasiService
{
    public function __construct(private readonly NotifikasiRepositoryInterface $repo) {}

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