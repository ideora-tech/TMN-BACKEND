<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use App\Modules\IntervalPerawatan\IntervalLabelBuilder;
use App\Modules\PerawatanArmada\Contracts\PerawatanArmadaRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PerawatanArmadaService
{
    public function __construct(
        private readonly PerawatanArmadaRepositoryInterface $repo,
        private readonly ArmadaRepositoryInterface $armadaRepo,
        private readonly IntervalPerawatanRepositoryInterface $intervalRepo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 10, ?string $idPerusahaan = null): array
    {
        if ($idPerusahaan !== null) {
            $armada = $this->armadaRepo->findById($idArmada);
            if ($armada === null || $armada->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Armada tidak ditemukan');
            }
        }

        $paged = $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
        $paged['data'] = $this->lampirkanInterval($this->lampirkanSupplier($paged['data']));
        return $paged;
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status, bool $jatuhTempo = false, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): array
    {
        $paged = $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $status, $jatuhTempo, $search, $tanggalDari, $tanggalSampai));
        $paged['data'] = $this->lampirkanInterval($this->lampirkanSupplier($paged['data']));
        return $paged;
    }

    /** @param object[] $records */
    private function lampirkanInterval(array $records): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r->id_interval_perawatan ?? null, $records))));
        $intervalMap = $this->repo->intervalUntukBanyak($ids);
        foreach ($records as $record) {
            $interval = ($record->id_interval_perawatan ?? null) !== null ? ($intervalMap[$record->id_interval_perawatan] ?? null) : null;
            $record->interval_label = $interval !== null
                ? IntervalLabelBuilder::build(
                    $interval->interval_km !== null ? (int) $interval->interval_km : null,
                    $interval->interval_bulan !== null ? (int) $interval->interval_bulan : null,
                )
                : null;
        }
        return $records;
    }

    /** @param object[] $records */
    private function lampirkanSupplier(array $records): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r->id_supplier ?? null, $records))));
        $namaMap = $this->repo->supplierUntukBanyak($ids);
        foreach ($records as $record) {
            $record->nama_supplier = ($record->id_supplier ?? null) !== null ? ($namaMap[$record->id_supplier] ?? null) : null;
        }
        return $records;
    }

    /**
     * Gabungkan interval_perawatan (paket servis per jenis kendaraan) + riwayat
     * servis terakhir jadi daftar prediksi paket apa saja yang akan datang untuk
     * 1 armada. Dua basis dihitung: BULAN (jadwal_servis_berikutnya vs hari ini)
     * dan KM (km servis terakhir + interval_km vs odometer terakhir armada;
     * ambang "segera" = sisa ≤ 10% interval_km) — status akhir mengambil yang
     * terburuk.
     */
    public function prediksiPerawatan(string $idArmada, string $idPerusahaan, int $days = 30): array
    {
        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }

        if ($armada->id_jenis_kendaraan === null) {
            return [];
        }

        $rules  = $this->intervalRepo->findAllByJenisKendaraan($idPerusahaan, $armada->id_jenis_kendaraan);
        $latest = collect($this->repo->getLatestPerIntervalByArmada($idArmada))->keyBy('id_interval_perawatan');
        $kmSekarang = $this->repo->kmOdometerTerakhir($idArmada);

        $sparepartPerInterval = collect($this->intervalRepo->findSparepartByIntervalIds(
            array_map(fn ($rule) => (string) $rule->id_interval_perawatan, $rules)
        ))->groupBy('id_interval_perawatan');

        $items = [];
        foreach ($rules as $rule) {
            $riwayat = $latest->get($rule->id_interval_perawatan);
            $item = $this->hitungItemPrediksi($rule, $riwayat, $kmSekarang, $days, $armada->tanggal_beli ?? null);
            $item['sparepart_standar'] = $sparepartPerInterval
                ->get($rule->id_interval_perawatan, collect())
                ->values()
                ->all();
            $items[] = $item;
        }

        $rank = ['lewat_jatuh_tempo' => 0, 'belum_pernah' => 1, 'segera' => 2, 'aman' => 3];
        usort($items, function (array $a, array $b) use ($rank) {
            $cmp = $rank[$a['status']] <=> $rank[$b['status']];
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a['jadwal_servis_berikutnya'] ?? '9999-99-99') <=> ($b['jadwal_servis_berikutnya'] ?? '9999-99-99');
        });

        return $items;
    }

    /**
     * $tanggalPembelian = armada.tanggal_beli — dipakai sebagai titik mulai jadwal
     * servis PERTAMA (basis bulan saja) bila unit belum pernah punya riwayat servis
     * untuk paket ini. Anchor ini diabaikan bila jatuh temponya sudah lewat lebih
     * dari satu interval: unit lama yang riwayat servisnya tidak tercatat di sistem
     * dilaporkan 'belum_pernah', bukan 'lewat ribuan hari'.
     * Basis KM tidak punya anchor serupa (tetap butuh servis pertama).
     */
    private function hitungItemPrediksi(object $rule, ?object $riwayat, ?int $kmSekarang, int $days, ?string $tanggalPembelian = null): array
    {
        $tanggalTerakhir = $riwayat->tanggal ?? null;
        $intervalBulan = isset($rule->interval_bulan) && $rule->interval_bulan !== null ? (int) $rule->interval_bulan : null;

        $jadwalBerikutnya = $riwayat->jadwal_servis_berikutnya ?? null;
        if ($jadwalBerikutnya === null && $tanggalTerakhir !== null && $intervalBulan !== null) {
            $jadwalBerikutnya = Carbon::parse($tanggalTerakhir)->addMonths($intervalBulan)->toDateString();
        }
        if ($jadwalBerikutnya === null && $riwayat === null && $tanggalPembelian !== null && $intervalBulan !== null) {
            $kandidat = Carbon::parse($tanggalPembelian)->addMonths($intervalBulan);
            if ($kandidat->greaterThanOrEqualTo(Carbon::today()->subMonths($intervalBulan))) {
                $jadwalBerikutnya = $kandidat->toDateString();
            }
        }

        $sisaHari = null;
        $statusHari = 'belum_pernah';
        if ($jadwalBerikutnya !== null) {
            $sisaHari = (int) Carbon::today()->diffInDays(Carbon::parse($jadwalBerikutnya)->startOfDay(), false);
            $statusHari = match (true) {
                $sisaHari < 0      => 'lewat_jatuh_tempo',
                $sisaHari <= $days => 'segera',
                default            => 'aman',
            };
        }

        $intervalKm       = isset($rule->interval_km) && $rule->interval_km !== null ? (int) $rule->interval_km : null;
        $kmServisTerakhir = isset($riwayat->km_odometer) && $riwayat->km_odometer !== null ? (int) $riwayat->km_odometer : null;

        $kmJatuhTempo = null;
        $sisaKm = null;
        $statusKm = null;
        if ($intervalKm !== null && $kmServisTerakhir !== null && $kmSekarang !== null) {
            $kmJatuhTempo = $kmServisTerakhir + $intervalKm;
            $sisaKm = $kmJatuhTempo - $kmSekarang;
            $ambangKm = max(1, (int) round($intervalKm * 0.1));
            $statusKm = match (true) {
                $sisaKm < 0         => 'lewat_jatuh_tempo',
                $sisaKm <= $ambangKm => 'segera',
                default             => 'aman',
            };
        }

        $status = $statusHari;
        if ($statusKm !== null) {
            $urutan = ['lewat_jatuh_tempo' => 0, 'segera' => 1, 'aman' => 2, 'belum_pernah' => 3];
            if ($urutan[$statusKm] < $urutan[$status]) {
                $status = $statusKm;
            }
        }

        return [
            'id_interval_perawatan'    => $rule->id_interval_perawatan,
            'label'                    => IntervalLabelBuilder::build($intervalKm, $intervalBulan),
            'interval_bulan'           => $intervalBulan,
            'interval_km'              => $intervalKm,
            'tanggal_servis_terakhir'  => $tanggalTerakhir,
            'jadwal_servis_berikutnya' => $jadwalBerikutnya,
            'km_servis_terakhir'       => $kmServisTerakhir,
            'km_sekarang'              => $kmSekarang,
            'km_jatuh_tempo'           => $kmJatuhTempo,
            'sisa_km'                  => $sisaKm,
            'status_km'                => $statusKm,
            'status'                   => $status,
            'status_hari'              => $statusHari,
            'sisa_hari'                => $sisaHari,
        ];
    }

    public function papanUnit(string $idPerusahaan, int $page = 1, int $limit = 20, ?string $search = null, bool $hanyaJatuhTempo = false): array
    {
        $armadaList = $this->repo->findArmadaPapanUnit($idPerusahaan, $search);
        if (empty($armadaList)) {
            return $this->toManualPagedArray([], $page, $limit);
        }

        $armadaIds = array_map(fn ($a) => $a->id_armada, $armadaList);

        $jenisKendaraanIds = collect($armadaList)->pluck('id_jenis_kendaraan')->filter()->unique()->values()->all();

        $rulesPerJenisKendaraan = collect($this->intervalRepo->findAllByJenisKendaraanIds($idPerusahaan, $jenisKendaraanIds))
            ->groupBy('id_jenis_kendaraan');

        $latestPerArmada = collect($this->repo->getLatestPerIntervalByArmadaIds($armadaIds))
            ->groupBy('id_armada')
            ->map(fn ($rows) => collect($rows)->keyBy('id_interval_perawatan'));

        $kmMap = $this->repo->kmOdometerTerakhirByArmadaIds($armadaIds);

        $servisTerakhirMap = collect($this->repo->getServisTerakhirSelesaiByArmadaIds($armadaIds))
            ->keyBy('id_armada');

        $rows = [];
        foreach ($armadaList as $armada) {
            $rules = $armada->id_jenis_kendaraan !== null
                ? $rulesPerJenisKendaraan->get($armada->id_jenis_kendaraan, collect())
                : collect();
            $latestByInterval = $latestPerArmada->get($armada->id_armada, collect());
            $kmSekarang = isset($kmMap[$armada->id_armada]) ? (int) $kmMap[$armada->id_armada] : null;

            $jatuhTempo = [];
            foreach ($rules as $rule) {
                $riwayat = $latestByInterval->get($rule->id_interval_perawatan);
                $item = $this->hitungItemPrediksi($rule, $riwayat, $kmSekarang, 30, $armada->tanggal_beli ?? null);

                if (in_array($item['status_hari'], ['segera', 'lewat_jatuh_tempo'], true)) {
                    $jatuhTempo[] = [
                        'id_interval_perawatan' => $rule->id_interval_perawatan,
                        'label'                 => $item['label'],
                        'basis'                 => 'hari',
                        'status'                => $item['status_hari'],
                        'keterangan'            => $this->keteranganHari((int) $item['sisa_hari']),
                    ];
                }

                if (in_array($item['status_km'], ['segera', 'lewat_jatuh_tempo'], true)) {
                    $jatuhTempo[] = [
                        'id_interval_perawatan' => $rule->id_interval_perawatan,
                        'label'                 => $item['label'],
                        'basis'                 => 'km',
                        'status'                => $item['status_km'],
                        'keterangan'            => $this->keteranganKm((int) $item['sisa_km']),
                    ];
                }
            }

            usort($jatuhTempo, fn ($a, $b) => ($a['status'] === 'lewat_jatuh_tempo' ? 0 : 1) <=> ($b['status'] === 'lewat_jatuh_tempo' ? 0 : 1));

            $servis = $servisTerakhirMap->get($armada->id_armada);

            $rows[] = [
                'id_armada'            => $armada->id_armada,
                'nopol'                => $armada->nopol,
                'merk'                 => $armada->merk,
                'id_jenis_kendaraan'   => $armada->id_jenis_kendaraan,
                'nama_jenis_kendaraan' => $armada->nama_jenis_kendaraan,
                'status_armada'        => $armada->status_armada,
                'servis_terakhir'      => $servis !== null ? [
                    'tanggal' => $servis->tanggal,
                    'label'   => $servis->label,
                ] : null,
                'belum_pernah_servis'  => $servis === null,
                'jumlah_interval'      => $rules->count(),
                'jatuh_tempo'          => $jatuhTempo,
            ];
        }

        if ($hanyaJatuhTempo) {
            $rows = array_values(array_filter($rows, fn ($r) => !empty($r['jatuh_tempo'])));
        }

        return $this->toManualPagedArray($rows, $page, $limit);
    }

    private function keteranganHari(int $sisaHari): string
    {
        return $sisaHari < 0
            ? 'lewat ' . abs($sisaHari) . ' hari'
            : $sisaHari . ' hari lagi';
    }

    private function keteranganKm(int $sisaKm): string
    {
        return $sisaKm < 0
            ? 'lewat ' . number_format(abs($sisaKm), 0, ',', '.') . ' km'
            : 'sisa ' . number_format($sisaKm, 0, ',', '.') . ' km';
    }

    private function toManualPagedArray(array $items, int $page, int $limit): array
    {
        $total = count($items);

        return [
            'data' => array_values(array_slice($items, ($page - 1) * $limit, $limit)),
            'meta' => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'totalPages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
            ],
        ];
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

    public function rekapPerUnit(string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        return array_map(fn ($row) => [
            'id_armada'        => $row->id_armada,
            'nopol'            => $row->nopol,
            'merk'             => $row->merk,
            'jumlah_perawatan' => (int) $row->jumlah_perawatan,
            'qty_sparepart'    => (int) $row->qty_sparepart,
            'km_terakhir'      => $row->km_terakhir !== null ? (int) $row->km_terakhir : null,
            'tanggal_terakhir' => $row->tanggal_terakhir,
            'biaya_jasa'       => (float) $row->biaya_jasa,
            'biaya_sparepart'  => (float) $row->biaya_sparepart,
            'total_biaya'      => (float) $row->biaya_jasa + (float) $row->biaya_sparepart,
        ], $this->repo->rekapPerUnit($idPerusahaan, $dari, $sampai));
    }

    private function armadaMilikOrFail(string $idArmada, string $idPerusahaan): object
    {
        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }
        return $armada;
    }

    /** @param object[] $rows */
    private function petaRiwayatBiaya(array $rows): array
    {
        $lines = [];
        foreach ($this->repo->linesByPerawatanIds(array_map(fn ($r) => $r->id_perawatan, $rows)) as $line) {
            $lines[$line->id_perawatan][] = [
                'id_sparepart'   => $line->id_sparepart,
                'kode_sparepart' => $line->kode_sparepart,
                'nama_sparepart' => $line->nama_sparepart,
                'satuan'         => $line->satuan,
                'sumber'         => $line->sumber,
                'qty'            => (int) $line->qty,
                'harga'          => (float) $line->harga,
                'subtotal'       => (int) $line->qty * (float) $line->harga,
            ];
        }

        return array_map(fn ($r) => [
            'id_perawatan'    => $r->id_perawatan,
            'id_armada'       => $r->id_armada,
            'nopol'           => $r->nopol,
            'merk'            => $r->merk,
            'tanggal'         => $r->tanggal,
            'jenis_perawatan' => $r->jenis_perawatan,
            'status'          => $r->status,
            'km_odometer'     => $r->km_odometer !== null ? (int) $r->km_odometer : null,
            'nama_supplier'   => $r->nama_supplier,
            'keterangan'      => $r->keterangan,
            'biaya_jasa'      => (float) $r->biaya,
            'biaya_sparepart' => (float) $r->total_sparepart,
            'total_biaya'     => (float) $r->biaya + (float) $r->total_sparepart,
            'sparepart'       => $lines[$r->id_perawatan] ?? [],
        ], $rows);
    }

    /** @param object[] $rows */
    private function petaRekapSparepart(array $rows): array
    {
        return array_map(function ($r) {
            $qtyStok    = (int) $r->qty_stok;
            $qtyBengkel = (int) $r->qty_bengkel;
            $totalQty   = (int) $r->total_qty;

            return [
                'id_armada'        => $r->id_armada,
                'nopol'            => $r->nopol,
                'merk'             => $r->merk,
                'id_sparepart'     => $r->id_sparepart,
                'kode_sparepart'   => $r->kode_sparepart,
                'nama_sparepart'   => $r->nama_sparepart,
                'satuan'           => $r->satuan,
                'sumber'           => $qtyStok > 0 && $qtyBengkel > 0 ? 'campuran' : ($qtyStok > 0 ? 'stok_sendiri' : 'bengkel'),
                'total_qty'        => $totalQty,
                'harga_rata'       => $totalQty > 0 ? round((float) $r->total_biaya / $totalQty, 2) : 0.0,
                'total_biaya'      => (float) $r->total_biaya,
                'jumlah_perawatan' => (int) $r->jumlah_perawatan,
                'terakhir_dipakai' => $r->terakhir_dipakai,
            ];
        }, $rows);
    }

    public function riwayatBiayaUnit(string $idArmada, string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        $armada  = $this->armadaMilikOrFail($idArmada, $idPerusahaan);
        $riwayat = $this->petaRiwayatBiaya($this->repo->listByArmadaRentang($idArmada, $dari, $sampai));

        $jasa      = array_sum(array_column($riwayat, 'biaya_jasa'));
        $sparepart = array_sum(array_column($riwayat, 'biaya_sparepart'));
        $km        = array_values(array_filter(array_column($riwayat, 'km_odometer'), fn ($v) => $v !== null));

        return [
            'armada' => [
                'id_armada' => $armada->id_armada,
                'nopol'     => $armada->nopol,
                'merk'      => $armada->merk,
            ],
            'ringkasan' => [
                'jumlah_perawatan' => count($riwayat),
                'qty_sparepart'    => array_sum(array_map(fn ($r) => array_sum(array_column($r['sparepart'], 'qty')), $riwayat)),
                'biaya_jasa'       => (float) $jasa,
                'biaya_sparepart'  => (float) $sparepart,
                'total_biaya'      => (float) ($jasa + $sparepart),
                'km_terakhir'      => $km !== [] ? max($km) : null,
            ],
            'riwayat' => $riwayat,
        ];
    }

    public function rekapSparepartUnit(string $idArmada, string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        $this->armadaMilikOrFail($idArmada, $idPerusahaan);
        return $this->petaRekapSparepart($this->repo->rekapSparepart($idPerusahaan, $idArmada, $dari, $sampai));
    }

    public function dataExportRekap(string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        return [
            'rekap'     => $this->rekapPerUnit($idPerusahaan, $dari, $sampai),
            'riwayat'   => $this->petaRiwayatBiaya($this->repo->listRentangPerusahaan($idPerusahaan, $dari, $sampai)),
            'sparepart' => $this->petaRekapSparepart($this->repo->rekapSparepart($idPerusahaan, null, $dari, $sampai)),
        ];
    }

    public function dataExportUnit(string $idArmada, string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        $armada = $this->armadaMilikOrFail($idArmada, $idPerusahaan);

        return [
            'armada'    => $armada,
            'items'     => $this->repo->listByArmadaRentang($idArmada, $dari, $sampai),
            'sparepart' => $this->petaRekapSparepart($this->repo->rekapSparepart($idPerusahaan, $idArmada, $dari, $sampai)),
        ];
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    public function dataCetakDetail(string $idArmada, string $id, string $idPerusahaan): array
    {
        $perawatan = $this->findOrFail($id, $idPerusahaan);
        if ((string) $perawatan->id_armada !== $idArmada) {
            abort(404, 'Perawatan armada tidak ditemukan');
        }

        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }

        return ['perawatan' => $perawatan, 'armada' => $armada];
    }

    public function infoPengajuan(string $id, ?string $idPerusahaan = null): ?array
    {
        $this->findOrFail($id, $idPerusahaan);
        return $this->arusKasService->infoPengajuanPerawatan($id);
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && !$this->repo->milikPerusahaan($id, $idPerusahaan))) {
            abort(404, 'Perawatan armada tidak ditemukan');
        }

        $record->sparepart = array_map(fn ($line) => [
            'id_perawatan_sparepart' => $line->id_perawatan_sparepart,
            'id_sparepart'           => $line->id_sparepart,
            'nama_sparepart'         => $line->nama_sparepart,
            'qty'                    => (int) $line->qty,
            'harga'                  => (float) $line->harga,
            'sumber'                 => $line->sumber,
            'subtotal'               => (int) $line->qty * (float) $line->harga,
        ], $this->repo->getActiveLines($id));

        $record->nama_supplier = $record->id_supplier !== null ? $this->repo->getSupplierNama($record->id_supplier) : null;

        $armada = $this->armadaRepo->findById((string) $record->id_armada);
        $record->armada_nopol = $armada?->nopol;
        $record->armada_merk = $armada?->merk;

        $record->bukti = array_map(fn ($b) => [
            'id_bukti'  => $b->id_bukti,
            'url_file'  => PenyimpananBerkas::url($b->url_file),
            'nama_asli' => $b->nama_asli,
        ], $this->repo->listBukti($id));

        [$record] = $this->lampirkanInterval([$record]);

        return $record;
    }

    /** @param UploadedFile[] $files */
    public function tambahBukti(string $idPerawatan, array $files, ?string $idPerusahaan = null): object
    {
        $this->findOrFail($idPerawatan, $idPerusahaan);

        foreach ($files as $file) {
            $this->repo->insertBukti([
                'id_perawatan' => $idPerawatan,
                'url_file'     => PenyimpananBerkas::simpan($file, 'perawatan'),
                'nama_asli'    => $file->getClientOriginalName(),
            ]);
        }

        return $this->findOrFail($idPerawatan);
    }

    public function hapusBukti(string $idPerawatan, string $idBukti, ?string $idPerusahaan = null): void
    {
        $this->findOrFail($idPerawatan, $idPerusahaan);

        $bukti = $this->repo->findBukti($idPerawatan, $idBukti);
        if ($bukti === null) {
            abort(404, 'Bukti perawatan tidak ditemukan');
        }

        $this->repo->softDeleteBukti($idBukti);
    }

    public function create(string $idArmada, array $data): object
    {
        $items = $this->normalizeItems($data['sparepart'] ?? []);
        unset($data['sparepart']);

        $armada = $this->armadaRepo->findById($idArmada);
        $this->validasiSupplier($armada?->id_perusahaan, $data['id_supplier'] ?? null);

        $idInterval = $data['id_interval_perawatan'] ?? null;
        if ($idInterval !== null) {
            $this->validasiInterval($armada, $idInterval);
        }

        $overrideJadwal = $data['jadwal_servis_berikutnya'] ?? null;
        if ($overrideJadwal === null) {
            $tanggalServis = $data['tanggal'] ?? now()->toDateString();
            $data['jadwal_servis_berikutnya'] = $this->hitungJadwalOtomatis($idInterval, $tanggalServis);
        }

        return DB::transaction(function () use ($idArmada, $data, $items) {
            $record = $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
            $this->simpanItems($record->id_perawatan, $items);
            $hasil = $this->findOrFail($record->id_perawatan);
            $this->sinkronArusKas($hasil);
            return $hasil;
        });
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (in_array($record->status, ['selesai', 'dibatalkan'], true)) {
            abort(422, 'Perawatan yang sudah selesai atau dibatalkan tidak dapat diubah');
        }

        if (array_key_exists('id_supplier', $data)) {
            $this->validasiSupplier($idPerusahaan, $data['id_supplier']);
        }

        $adaItems = array_key_exists('sparepart', $data);
        $items = $this->normalizeItems($data['sparepart'] ?? []);
        unset($data['sparepart']);

        $armada = $this->armadaRepo->findById($record->id_armada);

        $idIntervalBaru = array_key_exists('id_interval_perawatan', $data) ? $data['id_interval_perawatan'] : $record->id_interval_perawatan;
        if (array_key_exists('id_interval_perawatan', $data) && $data['id_interval_perawatan'] !== null) {
            $this->validasiInterval($armada, $data['id_interval_perawatan']);
        }

        $perluHitungUlang = array_key_exists('id_interval_perawatan', $data) || array_key_exists('jadwal_servis_berikutnya', $data);
        $overrideJadwal = $data['jadwal_servis_berikutnya'] ?? null;
        if ($perluHitungUlang && $overrideJadwal === null) {
            $tanggalServis = $data['tanggal'] ?? $record->tanggal;
            $data['jadwal_servis_berikutnya'] = $this->hitungJadwalOtomatis($idIntervalBaru, $tanggalServis);
        }

        return DB::transaction(function () use ($record, $data, $items, $adaItems) {
            $this->repo->update($record, $data);
            if ($adaItems) {
                $this->gantiItemsDenganDelta($record->id_perawatan, $items);
            }
            $hasil = $this->findOrFail($record->id_perawatan);
            $this->sinkronArusKas($hasil);
            return $hasil;
        });
    }

    private function validasiInterval(?object $armada, string $idInterval): void
    {
        $interval = $this->intervalRepo->findById($idInterval);
        if ($interval === null || $armada === null || $interval->id_perusahaan !== $armada->id_perusahaan) {
            abort(404, 'Paket servis tidak ditemukan');
        }
        if ($interval->id_jenis_kendaraan !== $armada->id_jenis_kendaraan) {
            abort(422, 'Paket servis tidak sesuai jenis kendaraan unit ini');
        }
    }

    /** null bila tidak tertaut paket atau paket tidak punya interval_bulan (jadwal tetap kosong, bukan diisi paksa). */
    private function hitungJadwalOtomatis(?string $idInterval, string $tanggalServis): ?string
    {
        if ($idInterval === null) {
            return null;
        }

        $interval = $this->intervalRepo->findById($idInterval);
        if ($interval === null || $interval->interval_bulan === null) {
            return null;
        }

        return Carbon::parse($tanggalServis)->addMonths((int) $interval->interval_bulan)->toDateString();
    }

    private function sinkronArusKas(object $record): void
    {
        if (!in_array($record->status, ['dalam_proses', 'selesai'], true)) {
            return;
        }

        $totalBiaya = (float) $record->biaya + array_sum(array_column($record->sparepart, 'subtotal'));
        if ($totalBiaya <= 0) {
            return;
        }

        $this->arusKasService->buatPengajuanPerawatanOtomatis($record, $totalBiaya);
        $this->arusKasService->sinkronNominalPengajuanPerawatan($record->id_perawatan, $totalBiaya);
    }

    public function delete(string $id, string $alasan, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($record->status === 'selesai') {
            abort(422, 'Perawatan yang sudah selesai tidak dapat dihapus');
        }

        DB::transaction(function () use ($record, $alasan) {
            $this->repo->update($record, ['alasan_hapus' => $alasan]);
            if ($record->status !== 'dibatalkan') {
                $this->kembalikanStok($record->id_perawatan);
            }
            $this->repo->softDeleteLines($record->id_perawatan);
            $this->repo->delete($record);
            $this->arusKasService->hapusPengajuanPerawatan($record->id_perawatan);
        });
    }

    public function batal(string $id, string $alasan, ?string $idPerusahaan = null): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['terjadwal', 'dalam_proses'], true)) {
            abort(422, 'Hanya perawatan yang masih direncanakan atau dalam proses yang dapat dibatalkan');
        }

        return DB::transaction(function () use ($record, $alasan) {
            $this->kembalikanStok($record->id_perawatan);
            $this->repo->update($record, ['status' => 'dibatalkan', 'alasan_batal' => $alasan]);
            return $this->findOrFail($record->id_perawatan);
        });
    }

    /** Stok yang sudah dipotong saat servis dicatat dikembalikan + jejak mutasi masuk. Baris 'bengkel' tidak pernah memotong stok, jadi dilewati. */
    private function kembalikanStok(string $idPerawatan): void
    {
        foreach ($this->repo->getActiveLines($idPerawatan) as $line) {
            if ($line->sumber !== 'stok_sendiri') {
                continue;
            }
            $sp = $this->repo->getSparepartForUpdate($line->id_sparepart);
            if ($sp !== null) {
                $this->repo->setSparepartStok($sp->id_sparepart, (int) $sp->stok + (int) $line->qty);
                $this->repo->insertSparepartMutasi([
                    'id_sparepart' => $sp->id_sparepart,
                    'jenis'        => 'masuk',
                    'qty'          => (int) $line->qty,
                    'id_perawatan' => $idPerawatan,
                    'keterangan'   => 'Pembatalan servis',
                    'tanggal'      => now()->toDateString(),
                ]);
            }
        }
    }

    /**
     * sumber default 'bengkel' saat payload lama tidak mengirim field ini (kontrak baru
     * — pemanggil lama otomatis dianggap TIDAK menyentuh stok, bukan lagi memotong stok).
     * Baris 'bengkel' dengan id_sparepart terisi tapi nama_sparepart kosong diisi otomatis
     * dari master (hanya referensi tampilan, tidak mengunci/mengurangi stok).
     */
    private function normalizeItems(array $items): array
    {
        return array_map(function (array $item) {
            $item['sumber'] = $item['sumber'] ?? 'bengkel';
            $item['id_sparepart'] = $item['id_sparepart'] ?? null;
            $item['nama_sparepart'] = $item['nama_sparepart'] ?? null;

            if ($item['sumber'] === 'bengkel' && $item['id_sparepart'] !== null
                && ($item['nama_sparepart'] === null || $item['nama_sparepart'] === '')) {
                $item['nama_sparepart'] = $this->repo->getSparepartNama($item['id_sparepart']);
            }

            return $item;
        }, $items);
    }

    private function validasiSupplier(?string $idPerusahaan, ?string $idSupplier): void
    {
        if ($idSupplier === null) {
            return;
        }
        if ($idPerusahaan === null || !$this->repo->supplierMilik($idPerusahaan, $idSupplier)) {
            abort(404, 'Supplier tidak ditemukan');
        }
    }

    /** Create path: pisahkan sumber — stok_sendiri lewat jalur potong stok lama, bengkel langsung dicatat sebagai rincian biaya. */
    private function simpanItems(string $idPerawatan, array $items): void
    {
        $stokSendiri = array_values(array_filter($items, fn (array $i) => $i['sumber'] === 'stok_sendiri'));
        $bengkel = array_values(array_filter($items, fn (array $i) => $i['sumber'] === 'bengkel'));

        $this->keluarkanStokUntukItems($idPerawatan, $stokSendiri);
        $this->simpanItemBengkel($idPerawatan, $bengkel);
    }

    /** Baris 'bengkel' TIDAK menyentuh stok & TIDAK mencatat mutasi — murni rincian biaya, disimpan apa adanya tanpa digabung antar baris. */
    private function simpanItemBengkel(string $idPerawatan, array $items): void
    {
        foreach ($items as $item) {
            $this->repo->insertLine([
                'id_perawatan'   => $idPerawatan,
                'id_sparepart'   => $item['id_sparepart'],
                'nama_sparepart' => $item['nama_sparepart'],
                'sumber'         => 'bengkel',
                'qty'            => (int) $item['qty'],
                'harga'          => (float) $item['harga'],
            ]);
        }
    }

    /** Create path (khusus sumber stok_sendiri): kunci baris sparepart, validasi stok, insert line + mutasi keluar. */
    private function keluarkanStokUntukItems(string $idPerawatan, array $items): void
    {
        foreach ($this->totalPerSparepart($items) as $idSparepart => $agg) {
            $sp = $this->repo->getSparepartForUpdate($idSparepart);
            if ($sp === null) {
                abort(422, 'Spare part tidak ditemukan');
            }

            $stokBaru = (int) $sp->stok - $agg['qty'];
            if ($stokBaru < 0) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersedia {$sp->stok}, dibutuhkan {$agg['qty']})");
            }

            $this->repo->setSparepartStok($idSparepart, $stokBaru);
            $this->repo->insertLine([
                'id_perawatan'   => $idPerawatan,
                'id_sparepart'   => $idSparepart,
                'nama_sparepart' => $sp->nama,
                'sumber'         => 'stok_sendiri',
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

    /**
     * Update path: sparepart[] dikirim = daftar penuh baru (replace), untuk KEDUA sumber.
     * Delta stok hanya dihitung dari baris stok_sendiri lama vs baru — baris 'bengkel'
     * (lama maupun baru) sama sekali diabaikan dari perhitungan stok.
     */
    private function gantiItemsDenganDelta(string $idPerawatan, array $items): void
    {
        $lama = [];
        foreach ($this->repo->getActiveLines($idPerawatan) as $line) {
            if ($line->sumber !== 'stok_sendiri') {
                continue;
            }
            $lama[$line->id_sparepart] = ($lama[$line->id_sparepart] ?? 0) + (int) $line->qty;
        }

        $stokSendiriItems = array_values(array_filter($items, fn (array $i) => $i['sumber'] === 'stok_sendiri'));
        $bengkelItems = array_values(array_filter($items, fn (array $i) => $i['sumber'] === 'bengkel'));

        $baru = $this->totalPerSparepart($stokSendiriItems);
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

            $stokBaru = (int) $sp->stok - $delta;
            if ($delta > 0 && $stokBaru < 0) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersedia {$sp->stok}, tambahan dibutuhkan {$delta})");
            }

            $this->repo->setSparepartStok($idSparepart, $stokBaru);
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
                'sumber'         => 'stok_sendiri',
                'qty'            => $agg['qty'],
                'harga'          => $agg['harga'],
            ]);
        }
        $this->simpanItemBengkel($idPerawatan, $bengkelItems);
    }

    /** Gabungkan item duplikat (id_sparepart sama) — qty dijumlah, harga pakai yang terakhir. Hanya dipakai untuk baris sumber stok_sendiri. */
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
