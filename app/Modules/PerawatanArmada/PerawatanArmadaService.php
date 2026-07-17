<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerawatanArmadaService
{
    public function __construct(private readonly PerawatanArmadaRepositoryInterface $repo) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 10): array
    {
        return $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $status));
    }

    private function toPagedArray(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'page'       => $paginator->currentPage(),
                'limit'      => $paginator->perPage(),
                'total'      => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id): object
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Perawatan armada tidak ditemukan');
        }

        $record->sparepart = array_map(fn ($line) => [
            'id_perawatan_sparepart' => $line->id_perawatan_sparepart,
            'id_sparepart'           => $line->id_sparepart,
            'nama_sparepart'         => $line->nama_sparepart,
            'qty'                    => (int) $line->qty,
            'harga'                  => (float) $line->harga,
            'subtotal'               => (int) $line->qty * (float) $line->harga,
        ], $this->repo->getActiveLines($id));

        return $record;
    }

    public function create(string $idArmada, array $data): object
    {
        $items = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        $data = $this->applyJenisSnapshot($data);

        return DB::transaction(function () use ($idArmada, $data, $items) {
            $record = $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
            $this->keluarkanStokUntukItems($record->id_perawatan, $items);
            return $this->findOrFail($record->id_perawatan);
        });
    }

    public function update(string $id, array $data): object
    {
        $record = $this->findOrFail($id);
        $adaItems = array_key_exists('sparepart', $data);
        $items = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        $data = $this->applyJenisSnapshot($data);

        return DB::transaction(function () use ($record, $data, $items, $adaItems) {
            $this->repo->update($record, $data);
            if ($adaItems) {
                $this->gantiItemsDenganDelta($record->id_perawatan, $items);
            }
            return $this->findOrFail($record->id_perawatan);
        });
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);

        DB::transaction(function () use ($record) {
            foreach ($this->repo->getActiveLines($record->id_perawatan) as $line) {
                $sp = $this->repo->getSparepartForUpdate($line->id_sparepart);
                if ($sp !== null) {
                    $this->repo->setSparepartStok($sp->id_sparepart, (int) $sp->stok + (int) $line->qty);
                    $this->repo->insertSparepartMutasi([
                        'id_sparepart' => $sp->id_sparepart,
                        'jenis'        => 'masuk',
                        'qty'          => (int) $line->qty,
                        'id_perawatan' => $record->id_perawatan,
                        'keterangan'   => 'Pembatalan servis',
                        'tanggal'      => now()->toDateString(),
                    ]);
                }
            }
            $this->repo->softDeleteLines($record->id_perawatan);
            $this->repo->delete($record);
        });
    }

    /**
     * id_jenis_perawatan = sumber kebenaran; kolom teks jenis_perawatan di-sync
     * sebagai snapshot nama master (pola sama dgn jadwal_keberangkatan.rute + id_rute).
     * Teks manual tetap diizinkan kalau id tidak dikirim (required_without di Request).
     */
    private function applyJenisSnapshot(array $data): array
    {
        if (!empty($data['id_jenis_perawatan'])) {
            $nama = $this->repo->getJenisPerawatanNama($data['id_jenis_perawatan']);
            if ($nama !== null) {
                $data['jenis_perawatan'] = $nama;
            }
        }
        return $data;
    }

    /** Create path: kunci baris sparepart, validasi stok, insert line + mutasi keluar. */
    private function keluarkanStokUntukItems(string $idPerawatan, array $items): void
    {
        foreach ($this->totalPerSparepart($items) as $idSparepart => $agg) {
            $sp = $this->repo->getSparepartForUpdate($idSparepart);
            if ($sp === null) {
                abort(422, 'Spare part tidak ditemukan');
            }
            if ((int) $sp->stok < $agg['qty']) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersisa {$sp->stok}, diminta {$agg['qty']})");
            }

            $this->repo->setSparepartStok($idSparepart, (int) $sp->stok - $agg['qty']);
            $this->repo->insertLine([
                'id_perawatan'   => $idPerawatan,
                'id_sparepart'   => $idSparepart,
                'nama_sparepart' => $sp->nama,
                'qty'            => $agg['qty'],
                'harga'          => $agg['harga'],
            ]);
            $this->repo->insertSparepartMutasi([
                'id_sparepart' => $idSparepart,
                'jenis'        => 'keluar',
                'qty'          => $agg['qty'],
                'harga'        => $agg['harga'],
                'id_perawatan' => $idPerawatan,
                'keterangan'   => 'Pemakaian servis',
                'tanggal'      => now()->toDateString(),
            ]);
        }
    }

    /** Update path: hitung delta per sparepart vs lines aktif lama, koreksi stok + mutasi, replace lines. */
    private function gantiItemsDenganDelta(string $idPerawatan, array $items): void
    {
        $lama = [];
        foreach ($this->repo->getActiveLines($idPerawatan) as $line) {
            $lama[$line->id_sparepart] = ($lama[$line->id_sparepart] ?? 0) + (int) $line->qty;
        }

        $baru = $this->totalPerSparepart($items);
        $semuaId = array_unique(array_merge(array_keys($lama), array_keys($baru)));

        $namaMap = [];
        foreach ($semuaId as $idSparepart) {
            $qtyLama = $lama[$idSparepart] ?? 0;
            $qtyBaru = $baru[$idSparepart]['qty'] ?? 0;
            $delta = $qtyBaru - $qtyLama;

            $sp = $this->repo->getSparepartForUpdate($idSparepart);
            if ($sp === null) {
                abort(422, 'Spare part tidak ditemukan');
            }
            $namaMap[$idSparepart] = $sp->nama;

            if ($delta === 0) {
                continue;
            }
            if ($delta > 0 && (int) $sp->stok < $delta) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersisa {$sp->stok}, diminta tambahan {$delta})");
            }

            $this->repo->setSparepartStok($idSparepart, (int) $sp->stok - $delta);
            $this->repo->insertSparepartMutasi([
                'id_sparepart' => $idSparepart,
                'jenis'        => $delta > 0 ? 'keluar' : 'masuk',
                'qty'          => abs($delta),
                'id_perawatan' => $idPerawatan,
                'keterangan'   => 'Perubahan item servis',
                'tanggal'      => now()->toDateString(),
            ]);
        }

        $this->repo->softDeleteLines($idPerawatan);
        foreach ($baru as $idSparepart => $agg) {
            $this->repo->insertLine([
                'id_perawatan'   => $idPerawatan,
                'id_sparepart'   => $idSparepart,
                'nama_sparepart' => $namaMap[$idSparepart],
                'qty'            => $agg['qty'],
                'harga'          => $agg['harga'],
            ]);
        }
    }

    /** Gabungkan item duplikat (id_sparepart sama) — qty dijumlah, harga pakai yang terakhir. */
    private function totalPerSparepart(array $items): array
    {
        $agg = [];
        foreach ($items as $item) {
            $id = $item['id_sparepart'];
            $agg[$id] = [
                'qty'   => ($agg[$id]['qty'] ?? 0) + (int) $item['qty'],
                'harga' => (float) $item['harga'],
            ];
        }
        return $agg;
    }
}
