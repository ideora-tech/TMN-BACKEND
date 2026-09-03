<?php

declare(strict_types=1);

namespace App\Modules\PerawatanArmada;

use App\Modules\Armada\Contracts\ArmadaRepositoryInterface;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\IntervalPerawatan\Contracts\IntervalPerawatanRepositoryInterface;
use App\Modules\PaketPerawatanSparepart\Contracts\PaketPerawatanSparepartRepositoryInterface;
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
        private readonly PaketPerawatanSparepartRepositoryInterface $paketRepo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public function listByArmada(string $idArmada, int $page = 1, int $limit = 10): array
    {
        return $this->toPagedArray($this->repo->paginateByArmada($idArmada, $page, $limit));
    }

    public function listByPerusahaan(string $idPerusahaan, int $page, int $limit, ?string $idArmada, ?string $status, bool $jatuhTempo = false, ?string $search = null, ?string $tanggalDari = null, ?string $tanggalSampai = null): array
    {
        return $this->toPagedArray($this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $idArmada, $status, $jatuhTempo, $search, $tanggalDari, $tanggalSampai));
    }

    /**
     * Gabungkan interval_perawatan (aturan) + riwayat servis terakhir + paket sparepart standar
     * jadi daftar prediksi jenis perawatan apa saja yang akan datang untuk 1 armada.
     * Dua basis dihitung: HARI (jadwal_servis_berikutnya vs hari ini) dan KM
     * (km servis terakhir + interval_km vs odometer terakhir armada; ambang
     * "segera" = sisa ≤ 10% interval_km) — status akhir mengambil yang terburuk.
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
        $latest = collect($this->repo->getLatestPerJenisByArmada($idArmada))->keyBy('id_jenis_perawatan');
        $kmSekarang = $this->repo->kmOdometerTerakhir($idArmada);

        $items = [];
        foreach ($rules as $rule) {
            $riwayat = $latest->get($rule->id_jenis_perawatan);
            $tanggalTerakhir = $riwayat->tanggal ?? null;

            $jadwalBerikutnya = $riwayat->jadwal_servis_berikutnya ?? null;
            if ($jadwalBerikutnya === null && $tanggalTerakhir !== null && $rule->interval_hari !== null) {
                $jadwalBerikutnya = Carbon::parse($tanggalTerakhir)->addDays((int) $rule->interval_hari)->toDateString();
            }

            $sisaHari = null;
            $status = 'belum_pernah';
            if ($jadwalBerikutnya !== null) {
                $sisaHari = (int) Carbon::today()->diffInDays(Carbon::parse($jadwalBerikutnya)->startOfDay(), false);
                $status = match (true) {
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

            if ($statusKm !== null) {
                $urutan = ['lewat_jatuh_tempo' => 0, 'segera' => 1, 'aman' => 2, 'belum_pernah' => 3];
                if ($urutan[$statusKm] < $urutan[$status]) {
                    $status = $statusKm;
                }
            }

            $items[] = [
                'id_jenis_perawatan'       => $rule->id_jenis_perawatan,
                'nama_jenis_perawatan'     => $rule->nama_jenis_perawatan,
                'interval_hari'            => $rule->interval_hari !== null ? (int) $rule->interval_hari : null,
                'interval_km'              => $intervalKm,
                'tanggal_servis_terakhir'  => $tanggalTerakhir,
                'jadwal_servis_berikutnya' => $jadwalBerikutnya,
                'km_servis_terakhir'       => $kmServisTerakhir,
                'km_sekarang'              => $kmSekarang,
                'km_jatuh_tempo'           => $kmJatuhTempo,
                'sisa_km'                  => $sisaKm,
                'status_km'                => $statusKm,
                'status'                   => $status,
                'sisa_hari'                => $sisaHari,
                'sparepart_standar'        => $this->paketRepo->resolusiList($idPerusahaan, $rule->id_jenis_perawatan, $armada->id_jenis_kendaraan),
            ];
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
            'biaya_jasa'       => (float) $row->biaya_jasa,
            'biaya_sparepart'  => (float) $row->biaya_sparepart,
            'total_biaya'      => (float) $row->biaya_jasa + (float) $row->biaya_sparepart,
        ], $this->repo->rekapPerUnit($idPerusahaan, $dari, $sampai));
    }

    public function dataExportUnit(string $idArmada, string $idPerusahaan, ?string $dari = null, ?string $sampai = null): array
    {
        $armada = $this->armadaRepo->findById($idArmada);
        if ($armada === null || $armada->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Armada tidak ditemukan');
        }

        return [
            'armada' => $armada,
            'items'  => $this->repo->listByArmadaRentang($idArmada, $dari, $sampai),
        ];
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
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
            'subtotal'               => (int) $line->qty * (float) $line->harga,
        ], $this->repo->getActiveLines($id));

        $record->bukti = array_map(fn ($b) => [
            'id_bukti'  => $b->id_bukti,
            'url_file'  => PenyimpananBerkas::url($b->url_file),
            'nama_asli' => $b->nama_asli,
        ], $this->repo->listBukti($id));

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
        $items = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        $data = $this->applyJenisSnapshot($data);

        return DB::transaction(function () use ($idArmada, $data, $items) {
            $record = $this->repo->create(array_merge($data, ['id_armada' => $idArmada]));
            $this->keluarkanStokUntukItems($record->id_perawatan, $items);
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

        $adaItems = array_key_exists('sparepart', $data);
        $items = $data['sparepart'] ?? [];
        unset($data['sparepart']);
        $data = $this->applyJenisSnapshot($data);

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
            abort(422, 'Hanya perawatan terjadwal atau dalam proses yang dapat dibatalkan');
        }

        return DB::transaction(function () use ($record, $alasan) {
            $this->kembalikanStok($record->id_perawatan);
            $this->repo->update($record, ['status' => 'dibatalkan', 'alasan_batal' => $alasan]);
            return $this->findOrFail($record->id_perawatan);
        });
    }

    /** Stok yang sudah dipotong saat servis dicatat dikembalikan + jejak mutasi masuk. */
    private function kembalikanStok(string $idPerawatan): void
    {
        foreach ($this->repo->getActiveLines($idPerawatan) as $line) {
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

            $stokBaru = (int) $sp->stok - $agg['qty'];
            if ($stokBaru < 0) {
                abort(422, "Stok {$sp->nama} tidak cukup (tersedia {$sp->stok}, dibutuhkan {$agg['qty']})");
            }

            $this->repo->setSparepartStok($idSparepart, $stokBaru);
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
