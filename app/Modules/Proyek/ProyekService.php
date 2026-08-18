<?php

declare(strict_types=1);

namespace App\Modules\Proyek;

use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Modules\Faktur\FakturModel;
use App\Modules\Faktur\FakturService;
use App\Modules\Penawaran\Contracts\PenawaranItemRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use App\Modules\Penawaran\PenawaranModel;
use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Modules\ProyekRute\ProyekRuteService;
use App\Support\KodeOtomatis;
use Illuminate\Support\Facades\DB;

class ProyekService
{
    private const ALLOWED_STATUSES = ['draft', 'aktif', 'selesai', 'batal'];

    public function __construct(
        private readonly ProyekRepositoryInterface $repo,
        private readonly PenawaranRepositoryInterface $penawaranRepo,
        private readonly PenawaranItemRepositoryInterface $penawaranItemRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly ProyekRuteService $proyekRuteService,
        private readonly FakturService $fakturService,
        private readonly FakturRepositoryInterface $fakturRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null): array
    {
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

    public function listByKlien(string $idKlien, string $idPerusahaan, int $page = 1, int $limit = 20, ?string $search = null, ?string $status = null): array
    {
        $result = $this->repo->paginateByKlien($idKlien, $idPerusahaan, $page, $limit, $search, $status);

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

    public function findOrFail(string $id): ProyekModel
    {
        $record = $this->repo->findById($id);
        if ($record === null) {
            abort(404, 'Proyek tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): ProyekModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        $idPenawaran = $data['id_penawaran'] ?? null;
        $ruteManual  = $data['rute'] ?? [];
        unset($data['id_penawaran'], $data['rute']);
        $data['tipe_harga'] = $data['tipe_harga'] ?? 'per_rit';

        return DB::transaction(function () use ($data, $idPenawaran, $ruteManual, $idPerusahaan) {
            $penawaran = null;
            if ($idPenawaran !== null) {
                $penawaran = $this->penawaranRepo->findForUpdate($idPenawaran);
                if ($penawaran === null || $penawaran->id_perusahaan !== $idPerusahaan) {
                    abort(404, 'Penawaran tidak ditemukan');
                }
                if ($penawaran->status !== 'disetujui') {
                    abort(422, 'Penawaran belum disetujui — tidak bisa dijadikan proyek');
                }
                if ($penawaran->id_proyek !== null) {
                    abort(422, 'Penawaran sudah memiliki proyek');
                }

                $data['id_klien']        = $penawaran->id_klien;
                $data['tipe_harga']      = $penawaran->tipe_harga ?? 'per_rit';
                $data['harga_penawaran'] = $penawaran->nilai_penawaran;
                $data['status']          = 'aktif';
            }

            $data['kode_proyek'] = KodeOtomatis::berikutnya($idPerusahaan, 'proyek');
            if ($this->repo->findByKode($idPerusahaan, $data['kode_proyek'])) {
                abort(409, 'Kode proyek sudah digunakan');
            }

            $proyek = $this->repo->create($data);

            if ($penawaran !== null) {
                $terupdate = $this->penawaranRepo->tautkanProyekJikaBelum($penawaran->id_penawaran, $proyek->id_proyek);
                if ($terupdate === 0) {
                    abort(422, 'Penawaran sudah memiliki proyek');
                }
                $this->salinRuteDariPenawaran($proyek->id_proyek, $penawaran->id_penawaran, $idPerusahaan);
            }

            foreach ($ruteManual as $baris) {
                $this->proyekRuteService->create($proyek->id_proyek, $baris, $idPerusahaan);
            }

            return $proyek;
        });
    }

    public function buatPenawaranRevisi(string $idProyek, array $data, string $idPerusahaan): PenawaranModel
    {
        $proyek = $this->repo->findById($idProyek);
        if ($proyek === null || $proyek->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Proyek tidak ditemukan');
        }

        if (!$this->proyekRuteRepo->adaPenawaranDisetujui($idProyek)) {
            abort(422, 'Proyek belum punya penawaran disetujui');
        }

        if ($this->penawaranRepo->adaRevisiBerjalan($idProyek)) {
            abort(422, 'Masih ada penawaran revisi yang belum selesai');
        }

        $items = $data['items'] ?? [];
        $this->pastikanItemTidakDuplikat($items);

        $tipeHarga = $proyek->tipe_harga ?? 'per_rit';

        if ($tipeHarga === 'borongan') {
            if (!isset($data['nilai_penawaran'])) {
                abort(422, 'Nilai penawaran wajib diisi untuk revisi borongan');
            }
            $nilaiPenawaran = (float) $data['nilai_penawaran'];
        } else {
            if (count($items) < 1) {
                abort(422, 'Item rute minimal 1 baris untuk revisi per rit');
            }
            foreach ($items as $item) {
                if (($item['harga_satuan'] ?? null) === null) {
                    abort(422, 'Harga satuan wajib diisi untuk penawaran per rit');
                }
            }
            $nilaiPenawaran = $this->totalItemsRevisi($items);
        }

        return DB::transaction(function () use ($idProyek, $idPerusahaan, $proyek, $items, $tipeHarga, $nilaiPenawaran, $data) {
            $induk = $this->penawaranRepo->penawaranPertamaProyek($idProyek);

            $penawaran = $this->penawaranRepo->create([
                'id_perusahaan'      => $idPerusahaan,
                'id_klien'           => $proyek->id_klien,
                'nomor_penawaran'    => KodeOtomatis::berikutnya($idPerusahaan, 'penawaran'),
                'judul'              => $data['judul'] ?? ('Revisi ' . $induk->nomor_penawaran),
                'nilai_penawaran'    => $nilaiPenawaran,
                'status'             => 'draft',
                'tipe_harga'         => $tipeHarga,
                'catatan'            => $data['catatan'] ?? null,
                'id_proyek'          => $idProyek,
                'id_penawaran_induk' => $induk->id_penawaran,
            ]);

            foreach ($items as $item) {
                $this->simpanItemRevisi($penawaran, $item);
            }

            $penawaran->setRelation('items', $this->penawaranItemRepo->listByPenawaran($penawaran->id_penawaran));

            return $penawaran;
        });
    }

    private function pastikanItemTidakDuplikat(array $items): void
    {
        $kunci = [];
        foreach ($items as $item) {
            $k = json_encode([$item['id_rute'] ?? null, $item['id_jenis_kendaraan'] ?? null]);
            if (in_array($k, $kunci, true)) {
                abort(422, 'Terdapat rute duplikat dalam item revisi');
            }
            $kunci[] = $k;
        }
    }

    private function totalItemsRevisi(array $items): float
    {
        return collect($items)->sum(
            fn (array $i) => (float) ($i['harga_satuan'] ?? 0) * (int) ($i['estimasi_ritase'] ?? 1)
        );
    }

    private function simpanItemRevisi(PenawaranModel $penawaran, array $item): void
    {
        if ($this->penawaranItemRepo->ruteMilik($item['id_rute'], $penawaran->id_perusahaan) === null) {
            abort(404, 'Rute tidak ditemukan');
        }

        $idJenis = $item['id_jenis_kendaraan'] ?? null;
        if ($idJenis !== null && $this->penawaranItemRepo->jenisKendaraanMilik($idJenis, $penawaran->id_perusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }

        $ritase      = (int) ($item['estimasi_ritase'] ?? 1);
        $hargaSatuan = $item['harga_satuan'] ?? null;
        $this->penawaranItemRepo->create([
            'id_perusahaan'      => $penawaran->id_perusahaan,
            'id_penawaran'       => $penawaran->id_penawaran,
            'id_rute'            => $item['id_rute'],
            'id_jenis_kendaraan' => $idJenis,
            'harga_satuan'       => $hargaSatuan,
            'estimasi_ritase'    => $ritase,
            'subtotal'           => $hargaSatuan !== null ? (float) $hargaSatuan * $ritase : 0,
            'keterangan'         => $item['keterangan'] ?? null,
        ]);
    }

    private function salinRuteDariPenawaran(string $idProyek, string $idPenawaran, string $idPerusahaan): void
    {
        foreach ($this->penawaranItemRepo->listByPenawaran($idPenawaran) as $item) {
            $this->proyekRuteRepo->create([
                'id_perusahaan'      => $idPerusahaan,
                'id_proyek'          => $idProyek,
                'id_rute'            => $item->id_rute,
                'id_jenis_kendaraan' => $item->id_jenis_kendaraan,
                'harga_penawaran'    => $item->harga_satuan,
                'estimasi_ritase'    => (int) $item->estimasi_ritase,
                'keterangan'         => $item->keterangan,
            ]);
        }
    }

    public function buatFakturBorongan(string $idProyek, array $data, string $idPerusahaan): FakturModel
    {
        return DB::transaction(function () use ($idProyek, $data, $idPerusahaan) {
            $proyek = $this->repo->findByIdForUpdate($idProyek);
            if ($proyek === null || $proyek->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Proyek tidak ditemukan');
            }

            if (($proyek->tipe_harga ?? 'per_rit') !== 'borongan') {
                abort(422, 'Faktur termin hanya untuk proyek borongan');
            }

            $nominal      = (float) $data['nominal'];
            $totalFaktur  = $this->repo->totalFakturProyek($idProyek);
            $nilaiKontrak = $proyek->harga_penawaran !== null ? (float) $proyek->harga_penawaran : 0.0;
            $sisa         = $nilaiKontrak - $totalFaktur;

            if ($nominal > $sisa) {
                abort(422, 'Total faktur melebihi nilai kontrak — sisa Rp ' . number_format(floor($sisa), 0, ',', '.'));
            }

            return $this->fakturService->create([
                'id_perusahaan'  => $idPerusahaan,
                'nomor_faktur'   => $this->fakturRepo->nomorBerikutnya($idPerusahaan),
                'id_proyek'      => $proyek->id_proyek,
                'id_klien'       => $proyek->id_klien,
                'status'         => 'draft',
                'tanggal_faktur' => $data['tanggal_faktur'],
                'jatuh_tempo'    => $data['jatuh_tempo'] ?? null,
                'items'          => [[
                    'deskripsi'    => $data['uraian'],
                    'qty'          => 1,
                    'harga_satuan' => $nominal,
                ]],
            ]);
        });
    }

    public function ringkasanRealisasi(ProyekModel $proyek): array
    {
        $idProyek       = (string) $proyek->id_proyek;
        $tipeHarga      = $proyek->tipe_harga ?? 'per_rit';
        $trips          = $this->repo->tripSelesaiUntukRealisasi($idProyek);
        $totalRit       = count($trips);
        $nilaiPenawaran = $proyek->harga_penawaran !== null ? (float) $proyek->harga_penawaran : null;

        if ($tipeHarga === 'borongan') {
            $totalFaktur = $this->repo->totalFakturProyek($idProyek);

            return [
                'total_rit'              => $totalRit,
                'nilai_realisasi'        => $totalFaktur,
                'nilai_penawaran'        => $nilaiPenawaran,
                'sisa_belum_difakturkan' => ($nilaiPenawaran ?? 0.0) - $totalFaktur,
            ];
        }

        return [
            'total_rit'              => $totalRit,
            'nilai_realisasi'        => $this->nilaiRealisasiDariTrips($trips, $idProyek),
            'nilai_penawaran'        => $nilaiPenawaran,
            'sisa_belum_difakturkan' => null,
        ];
    }

    private function nilaiRealisasiDariTrips(array $trips, string $idProyek): float
    {
        $totalHarga = 0.0;
        $idLaporans = [];
        foreach ($trips as $row) {
            $idJenis = $row->id_jenis_kendaraan ?? $row->id_jenis_kendaraan_vendor ?? null;

            if ($row->id_rute !== null) {
                $baris = $this->proyekRuteRepo->findHarga(
                    $idProyek,
                    (string) $row->id_rute,
                    $idJenis !== null ? (string) $idJenis : null,
                );
                if ($baris !== null && $baris->harga_penawaran !== null) {
                    $totalHarga += (float) $baris->harga_penawaran;
                }
            }

            if ($row->id_laporan !== null) {
                $idLaporans[] = (string) $row->id_laporan;
            }
        }

        return $totalHarga + $this->repo->totalBiayaTagihanUntukLaporan($idLaporans);
    }

    public function update(string $id, array $data, string $idPerusahaan): ProyekModel
    {
        $record = $this->findOrFail($id);

        if (isset($data['kode_proyek']) && $data['kode_proyek'] !== $record->kode_proyek) {
            if ($this->repo->findByKode($idPerusahaan, $data['kode_proyek'])) {
                abort(409, 'Kode proyek sudah digunakan');
            }
        }

        return $this->repo->update($record, $data);
    }

    public function updateStatus(string $id, string $status): ProyekModel
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            abort(422, 'Status tidak valid');
        }

        $record = $this->findOrFail($id);

        return $this->repo->update($record, ['status' => $status]);
    }

    public function delete(string $id): void
    {
        $record = $this->findOrFail($id);
        $this->repo->delete($record);
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }
}
