<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use Illuminate\Support\Str;

class PenawaranService
{
    private const VALID_TRANSITIONS = [
        'draft'     => ['terkirim'],
        'terkirim'  => ['negosiasi', 'disetujui', 'ditolak'],
        'negosiasi' => ['disetujui', 'ditolak'],
        'disetujui' => [],
        'ditolak'   => [],
    ];

    public function __construct(private readonly PenawaranRepositoryInterface $repo) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        ?string $status = null
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): PenawaranModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Penawaran tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PenawaranModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByNomor($idPerusahaan, $data['nomor_penawaran'])) {
            abort(409, 'Nomor penawaran sudah digunakan');
        }

        $data['id_penawaran'] = (string) Str::uuid();
        $data['status']       = $data['status'] ?? 'draft';

        return $this->repo->create($data);
    }

    public function update(string $id, array $data, string $idPerusahaan): PenawaranModel
    {
        $record = $this->findOrFail($id);

        if ($record->status !== 'draft') {
            abort(422, 'Penawaran yang sudah dikirim tidak dapat diubah');
        }

        if (isset($data['nomor_penawaran']) && $data['nomor_penawaran'] !== $record->nomor_penawaran) {
            if ($this->repo->findByNomor($idPerusahaan, $data['nomor_penawaran'], $id)) {
                abort(409, 'Nomor penawaran sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function updateStatus(string $id, string $newStatus): PenawaranModel
    {
        $record      = $this->findOrFail($id);
        $currentStatus = $record->status;

        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            abort(422, 'Transisi status tidak valid');
        }

        return $this->repo->update($record, ['status' => $newStatus]);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

        if ($record->status !== 'draft') {
            abort(422, 'Hanya penawaran berstatus draft yang dapat dihapus');
        }

        $this->repo->delete($record);
    }
}