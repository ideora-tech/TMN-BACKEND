<?php
namespace App\Modules\Rute;
use App\Modules\Rute\Contracts\RuteRepositoryInterface;
use App\Modules\Lokasi\Contracts\LokasiRepositoryInterface;
use Illuminate\Support\Str;

class RuteService {
    public function __construct(
        private readonly RuteRepositoryInterface $repo,
        private readonly LokasiRepositoryInterface $lokasiRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null): array {
        $paginator = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search);
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

    public function findOrFail(string $id): RuteModel {
        $rute = $this->repo->findById($id);
        if (!$rute) abort(404, 'Rute tidak ditemukan');
        return $rute;
    }

    public function create(array $data): RuteModel {
        if ($this->repo->findByKode($data['id_perusahaan'], $data['kode_rute'])) {
            abort(409, 'Kode rute sudah digunakan');
        }
        $data = $this->resolveLokasi($data, $data['id_perusahaan']);
        $data['id_rute'] = Str::uuid()->toString();
        return $this->repo->create($data);
    }

    public function update(string $id, array $data): RuteModel {
        $rute = $this->findOrFail($id);
        if (isset($data['kode_rute']) && $data['kode_rute'] !== $rute->kode_rute) {
            if ($this->repo->findByKode($rute->id_perusahaan, $data['kode_rute'], $id)) {
                abort(409, 'Kode rute sudah digunakan');
            }
        }
        $data = $this->resolveLokasi($data, $rute->id_perusahaan);
        return $this->repo->update($rute, $data);
    }

    public function delete(string $id): void {
        $rute = $this->findOrFail($id);
        $this->repo->delete($rute);
    }

    /**
     * Bila id_lokasi_asal/id_lokasi_tujuan dikirim, ambil lokasi milik
     * perusahaan terkait lalu isi teks asal/tujuan dari nama_lokasi.
     * 404 kalau lokasi tidak ditemukan atau beda perusahaan.
     */
    private function resolveLokasi(array $data, string $idPerusahaan): array {
        if (!empty($data['id_lokasi_asal'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_asal']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['asal'] = $lokasi->nama_lokasi;
        }
        if (!empty($data['id_lokasi_tujuan'])) {
            $lokasi = $this->lokasiRepo->findById($data['id_lokasi_tujuan']);
            if ($lokasi === null || $lokasi->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Lokasi tidak ditemukan');
            }
            $data['tujuan'] = $lokasi->nama_lokasi;
        }
        return $data;
    }
}