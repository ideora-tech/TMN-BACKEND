<?php

declare(strict_types=1);

namespace App\Modules\IntervalPerawatan;

use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntervalPerawatanService
{
    public function __construct(private readonly IntervalPerawatanRepositoryInterface $repo) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $idJenisKendaraan = null,
        ?string $search = null,
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idJenisKendaraan, $search);
        $items = $result->items();
        $this->attachSparepart($items);

        return [
            'data' => $items,
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Interval perawatan tidak ditemukan');
        }
        return $record;
    }

    public function findDetailOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findDetailById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Interval perawatan tidak ditemukan');
        }
        $this->attachSparepart([$record]);
        return $record;
    }

    public function create(array $data): object
    {
        $idPerusahaan = $data['id_perusahaan'];
        $this->validasiReferensi($data, $idPerusahaan);

        $adaSparepart = array_key_exists('sparepart', $data);
        $sparepart = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        if ($adaSparepart) {
            $this->validasiSparepart($sparepart, $idPerusahaan);
        }

        $intervalKm = $data['interval_km'] ?? null;
        $intervalBulan = $data['interval_bulan'] ?? null;
        $this->validasiIntervalTerisi($intervalKm, $intervalBulan);

        if ($this->repo->findByKombinasi($idPerusahaan, $data['id_jenis_kendaraan'], $intervalKm, $intervalBulan) !== null) {
            abort(422, 'Paket servis dengan interval ini sudah ada untuk jenis kendaraan tersebut');
        }

        $data['id_interval_perawatan'] = (string) Str::uuid();

        return DB::transaction(function () use ($data, $adaSparepart, $sparepart) {
            $created = $this->repo->create($data);
            if ($adaSparepart) {
                $this->sinkronSparepart($created->id_interval_perawatan, $sparepart);
            }

            $detail = $this->repo->findDetailById($created->id_interval_perawatan);
            $this->attachSparepart([$detail]);
            return $detail;
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->validasiReferensi($data, $idPerusahaan);

        $adaSparepart = array_key_exists('sparepart', $data);
        $sparepart = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        if ($adaSparepart) {
            $this->validasiSparepart($sparepart, $idPerusahaan);
        }

        $idJenisKendaraan = $data['id_jenis_kendaraan'] ?? $record->id_jenis_kendaraan;
        $intervalKm = array_key_exists('interval_km', $data) ? $data['interval_km'] : $record->interval_km;
        $intervalBulan = array_key_exists('interval_bulan', $data) ? $data['interval_bulan'] : $record->interval_bulan;
        $this->validasiIntervalTerisi($intervalKm, $intervalBulan);

        if ($this->repo->findByKombinasi($idPerusahaan, $idJenisKendaraan, $intervalKm, $intervalBulan, $id) !== null) {
            abort(422, 'Paket servis dengan interval ini sudah ada untuk jenis kendaraan tersebut');
        }

        return DB::transaction(function () use ($record, $data, $adaSparepart, $sparepart, $id) {
            $this->repo->update($record, $data);
            if ($adaSparepart) {
                $this->sinkronSparepart($id, $sparepart);
            }

            $detail = $this->repo->findDetailById($id);
            $this->attachSparepart([$detail]);
            return $detail;
        });
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        DB::transaction(function () use ($record) {
            $this->repo->delete($record);
            $this->repo->softDeleteSparepartByInterval($record->id_interval_perawatan);
        });
    }

    private function validasiIntervalTerisi(?int $intervalKm, ?int $intervalBulan): void
    {
        if ($intervalKm === null && $intervalBulan === null) {
            abort(422, 'Isi minimal interval kilometer atau interval bulan');
        }
    }

    private function validasiReferensi(array $data, string $idPerusahaan): void
    {
        if (isset($data['id_jenis_kendaraan'])
            && $this->repo->jenisKendaraanMilik($data['id_jenis_kendaraan'], $idPerusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }
    }

    private function validasiSparepart(array $items, string $idPerusahaan): void
    {
        $ids = array_column($items, 'id_sparepart');
        if (count($ids) !== count(array_unique($ids))) {
            abort(422, 'Sparepart duplikat dalam satu paket');
        }
        foreach ($ids as $id) {
            if ($this->repo->sparepartMilik($id, $idPerusahaan) === null) {
                abort(404, 'Spare part tidak ditemukan');
            }
        }
    }

    /** Replace penuh baris interval_perawatan_sparepart utk interval ini sesuai payload terbaru. */
    private function sinkronSparepart(string $idIntervalPerawatan, array $items): void
    {
        $this->repo->softDeleteSparepartByInterval($idIntervalPerawatan);

        foreach ($items as $item) {
            $this->repo->createSparepart([
                'id_interval_perawatan' => $idIntervalPerawatan,
                'id_sparepart'          => $item['id_sparepart'],
                'qty_standar'           => $item['qty_standar'],
            ]);
        }
    }

    /** @param object[] $records */
    private function attachSparepart(array $records): void
    {
        $ids = array_values(array_unique(array_map(fn ($r) => $r->id_interval_perawatan, $records)));

        $grouped = collect($this->repo->findSparepartByIntervalIds($ids))
            ->groupBy('id_interval_perawatan');

        foreach ($records as $r) {
            $r->sparepart = $grouped->get($r->id_interval_perawatan, collect())->map(fn ($row) => [
                'id_sparepart'     => $row['id_sparepart'],
                'nama_sparepart'   => $row['nama_sparepart'],
                'satuan_sparepart' => $row['satuan_sparepart'],
                'qty_standar'      => $row['qty_standar'],
            ])->values()->all();
        }
    }
}
