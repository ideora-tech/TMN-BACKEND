<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Modules\Penawaran\Contracts\PenawaranItemRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use Illuminate\Support\Facades\DB;
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

    public function __construct(
        private readonly PenawaranRepositoryInterface $repo,
        private readonly PenawaranItemRepositoryInterface $itemRepo,
    ) {}

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
        $record->setRelation('items', $this->itemRepo->listByPenawaran($id));
        return $record;
    }

    public function create(array $data): PenawaranModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        if ($this->repo->findByNomor($idPerusahaan, $data['nomor_penawaran'])) {
            abort(409, 'Nomor penawaran sudah digunakan');
        }

        $items = $data['items'] ?? [];
        unset($data['items']);
        if (count($items) > 0) {
            $data['nilai_penawaran'] = $this->totalItems($items);
        }

        $data['id_penawaran'] = (string) Str::uuid();
        $data['status']       = $data['status'] ?? 'draft';

        return DB::transaction(function () use ($data, $items) {
            $record = $this->repo->create($data);

            foreach ($items as $item) {
                $this->simpanItem($record, $item);
            }

            return $this->findOrFail($record->id_penawaran);
        });
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

        return DB::transaction(function () use ($record, $data) {
            if (array_key_exists('items', $data)) {
                $items = $data['items'] ?? [];
                unset($data['items']);

                $this->itemRepo->deleteByPenawaran($record->id_penawaran);
                foreach ($items as $item) {
                    $this->simpanItem($record, $item);
                }
                if (count($items) > 0) {
                    $data['nilai_penawaran'] = $this->totalItems($items);
                }
            }

            $this->repo->update($record, $data);

            return $this->findOrFail($record->id_penawaran);
        });
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

    private function totalItems(array $items): float
    {
        return collect($items)->sum(
            fn (array $i) => (float) $i['harga_satuan'] * (int) ($i['estimasi_ritase'] ?? 1)
        );
    }

    private function simpanItem(PenawaranModel $penawaran, array $item): void
    {
        if ($this->itemRepo->ruteMilik($item['id_rute'], $penawaran->id_perusahaan) === null) {
            abort(404, 'Rute tidak ditemukan');
        }
        if ($this->itemRepo->jenisKendaraanMilik($item['id_jenis_kendaraan'], $penawaran->id_perusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }

        $ritase = (int) ($item['estimasi_ritase'] ?? 1);
        $this->itemRepo->create([
            'id_perusahaan'      => $penawaran->id_perusahaan,
            'id_penawaran'       => $penawaran->id_penawaran,
            'id_rute'            => $item['id_rute'],
            'id_jenis_kendaraan' => $item['id_jenis_kendaraan'],
            'id_tarif_rute'      => $item['id_tarif_rute'] ?? null,
            'harga_satuan'       => $item['harga_satuan'],
            'estimasi_ritase'    => $ritase,
            'subtotal'           => (float) $item['harga_satuan'] * $ritase,
            'keterangan'         => $item['keterangan'] ?? null,
        ]);
    }
}