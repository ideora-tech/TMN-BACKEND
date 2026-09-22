<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\PembelianSparepart\Contracts\PembelianSparepartRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PembelianSparepartService
{
    public const STATUS_DIAJUKAN          = 'diajukan';
    public const STATUS_DISETUJUI_MANAGER = 'disetujui_manager';
    public const STATUS_DISETUJUI_FINANCE = 'disetujui_finance';
    public const STATUS_DITOLAK           = 'ditolak';
    public const STATUS_DIBELI            = 'dibeli';
    public const STATUS_LUNAS             = 'lunas';

    public function __construct(
        private readonly PembelianSparepartRepositoryInterface $repo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public function list(string $idPerusahaan, int $page, int $limit, array $filter = []): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $filter);
        $batas = $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
        foreach ($result->items() as $item) {
            $item->wajib_pengadaan = self::melebihiBatas((float) $item->total_estimasi, $batas);
        }
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

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pengajuan pembelian tidak ditemukan');
        }
        $record->items = $this->repo->listItems($id);
        $record->bukti = array_map(fn ($b) => [
            'id_bukti'  => $b->id_bukti,
            'url_file'  => PenyimpananBerkas::url($b->url_file),
            'nama_asli' => $b->nama_asli,
        ], $this->repo->listBukti($id));
        $record->pembayaran = $this->susunDataPembayaran($id, $record);
        $record->wajib_pengadaan = $this->wajibPengadaan((float) $record->total_estimasi, $idPerusahaan);
        return $record;
    }

    private function wajibPengadaan(float $totalEstimasi, string $idPerusahaan): bool
    {
        return self::melebihiBatas($totalEstimasi, $this->arusKasService->batasRealisasiMandiri($idPerusahaan));
    }

    private static function melebihiBatas(float $totalEstimasi, float $batas): bool
    {
        return $totalEstimasi > $batas;
    }

    public function infoPengajuanKeuangan(string $idPembelian): ?array
    {
        return $this->arusKasService->infoPengajuanPembelian($idPembelian);
    }

    private function susunDataPembayaran(string $idPembelian, object $record): ?array
    {
        $pembayaran = $this->repo->dataPembayaranPengajuan($idPembelian);
        if ($pembayaran === null) {
            return null;
        }

        $nominalDitransfer = (float) $pembayaran->nominal_ditransfer;
        $totalAktual = $record->total_aktual !== null ? (float) $record->total_aktual : null;

        return [
            'nominal_ditransfer' => $nominalDitransfer,
            'tanggal_transfer'   => $pembayaran->tanggal_transfer,
            'url_bukti'          => PenyimpananBerkas::url($pembayaran->url_bukti),
            'total_aktual'       => $totalAktual,
            'selisih'            => $totalAktual !== null ? $totalAktual - $nominalDitransfer : null,
        ];
    }

    /** @param UploadedFile[] $bukti */
    public function create(array $data, array $bukti, string $idPerusahaan): object
    {
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        return DB::transaction(function () use ($header, $items, $bukti, $idPerusahaan) {
            $header['id_perusahaan']   = $idPerusahaan;
            $header['status']          = self::STATUS_DIAJUKAN;
            $header['nomor_pengajuan'] = $this->repo->nomorBerikutnya($idPerusahaan);
            if ($header['id_perawatan'] !== null) {
                $header = $this->stampDisetujuiOtomatis($header);
            }
            $record = $this->repo->createWithItems($header, $items);
            $this->simpanBukti($record->id_pembelian, $bukti);
            $hasil = $this->findOrFail($record->id_pembelian, $idPerusahaan);
            if ($hasil->id_perawatan === null) {
                $this->arusKasService->buatPengajuanPembelianOtomatis($hasil, (float) $hasil->total_estimasi);
            }
            return $hasil;
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBolehDiubah($record);
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        if ($record->id_perawatan === null && $header['id_perawatan'] !== null) {
            $header = $this->stampDisetujuiOtomatis($header);
        } elseif ($record->id_perawatan !== null && $header['id_perawatan'] === null) {
            $header = $this->stampKembaliDiajukan($header);
        }
        return DB::transaction(function () use ($record, $header, $items, $idPerusahaan) {
            $this->repo->updateWithItems($record, $header, $items);
            $hasil = $this->findOrFail($record->id_pembelian, $idPerusahaan);
            $this->sinkronArusKasSetelahUpdate($record, $hasil);
            return $hasil;
        });
    }

    private function stampDisetujuiOtomatis(array $header): array
    {
        $header['status']                 = self::STATUS_DISETUJUI_FINANCE;
        $header['disetujui_manager_oleh'] = auth()->id();
        $header['disetujui_manager_pada'] = now();
        $header['disetujui_finance_oleh'] = auth()->id();
        $header['disetujui_finance_pada'] = now();
        return $header;
    }

    private function stampKembaliDiajukan(array $header): array
    {
        $header['status']                 = self::STATUS_DIAJUKAN;
        $header['disetujui_manager_oleh'] = null;
        $header['disetujui_manager_pada'] = null;
        $header['disetujui_finance_oleh'] = null;
        $header['disetujui_finance_pada'] = null;
        return $header;
    }

    private function bolehDiubahAtauDihapus(object $record): bool
    {
        return $record->status === self::STATUS_DIAJUKAN
            || ($record->status === self::STATUS_DISETUJUI_FINANCE && $record->id_perawatan !== null);
    }

    private function pastikanBolehDiubah(object $record): void
    {
        if (!$this->bolehDiubahAtauDihapus($record)) {
            abort(422, "Pengajuan hanya bisa diubah saat status diajukan (status saat ini: {$record->status})");
        }
    }

    private function pastikanBolehDihapus(object $record): void
    {
        if (!$this->bolehDiubahAtauDihapus($record)) {
            abort(422, "Pengajuan hanya bisa dihapus saat status diajukan (status saat ini: {$record->status})");
        }
    }

    private function sinkronArusKasSetelahUpdate(object $sebelum, object $sesudah): void
    {
        if ($sebelum->id_perawatan === null && $sesudah->id_perawatan !== null) {
            $this->arusKasService->hapusPengajuanPembelian($sesudah->id_pembelian);
            return;
        }

        if ($sesudah->id_perawatan === null) {
            $this->arusKasService->buatPengajuanPembelianOtomatis($sesudah, (float) $sesudah->total_estimasi);
            $this->arusKasService->sinkronNominalPengajuanPembelian($sesudah->id_pembelian, (float) $sesudah->total_estimasi);
        }
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBolehDihapus($record);
        DB::transaction(function () use ($record) {
            $this->repo->softDelete($record);
            $this->arusKasService->hapusPengajuanPembelian($record->id_pembelian);
        });
    }

    private function susunHeaderItems(array $data, string $idPerusahaan): array
    {
        $idSupplier = !empty($data['id_supplier']) ? (string) $data['id_supplier'] : null;
        if ($idSupplier !== null && !$this->repo->supplierMilik($idPerusahaan, $idSupplier)) {
            abort(422, 'Supplier tidak ditemukan');
        }
        if (!empty($data['id_perawatan']) && !$this->repo->perawatanMilik($idPerusahaan, $data['id_perawatan'])) {
            abort(422, 'Perawatan armada tidak ditemukan');
        }

        $ids = array_column($data['items'], 'id_sparepart');
        $spareparts = $this->repo->sparepartMilik($idPerusahaan, $ids);
        $items = [];
        $total = 0.0;
        foreach ($data['items'] as $item) {
            $master = $spareparts[$item['id_sparepart']] ?? null;
            if ($master === null) {
                abort(422, 'Spare part tidak ditemukan di perusahaan Anda');
            }
            $harga = (float) $item['harga_estimasi'];
            $items[] = [
                'id_sparepart'   => $item['id_sparepart'],
                'nama_sparepart' => $master->nama,
                'qty'            => (int) $item['qty'],
                'harga_estimasi' => $harga,
            ];
            $total += ((int) $item['qty']) * $harga;
        }

        $header = [
            'id_supplier'       => $idSupplier,
            'id_perawatan'      => $data['id_perawatan'] ?? null,
            'tanggal_pengajuan' => $data['tanggal_pengajuan'],
            'keterangan'        => $data['keterangan'] ?? null,
            'total_estimasi'    => $total,
        ];
        return [$header, $items];
    }

    private function pastikanStatus(object $record, array $boleh, string $pesan): void
    {
        if (!in_array($record->status, $boleh, true)) {
            abort(422, $pesan . " (status saat ini: {$record->status})");
        }
    }

    public function tambahBukti(string $id, array $files, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN, self::STATUS_DISETUJUI_MANAGER, self::STATUS_DISETUJUI_FINANCE, self::STATUS_DIBELI, self::STATUS_LUNAS], 'Bukti tidak bisa diunggah pada pengajuan yang ditolak');
        $this->simpanBukti($id, $files);
        return $this->findOrFail($id, $idPerusahaan);
    }

    /** @param UploadedFile[] $files */
    private function simpanBukti(string $idPembelian, array $files): void
    {
        foreach ($files as $file) {
            $this->repo->insertBukti([
                'id_pembelian' => $idPembelian,
                'url_file'     => PenyimpananBerkas::simpan($file, 'pembelian-sparepart'),
                'nama_asli'    => $file->getClientOriginalName(),
            ]);
        }
    }

    public function hapusBukti(string $id, string $idBukti, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN, self::STATUS_DISETUJUI_MANAGER, self::STATUS_DISETUJUI_FINANCE, self::STATUS_DIBELI], 'Bukti tidak bisa dihapus pada status ini');
        $bukti = $this->repo->findBukti($id, $idBukti);
        if ($bukti === null) {
            abort(404, 'Bukti tidak ditemukan');
        }
        if (in_array($record->status, [self::STATUS_DIAJUKAN, self::STATUS_DISETUJUI_MANAGER], true) && count($record->bukti) <= 1) {
            abort(422, 'Pengajuan yang masih menunggu approval wajib memiliki minimal satu lampiran');
        }
        $this->repo->softDeleteBukti($idBukti);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function realisasi(string $id, array $data, string $idPerusahaan, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_FINANCE], 'Realisasi hanya bisa dilakukan setelah disetujui finance');
        if (count($record->bukti) === 0) {
            abort(422, 'Unggah minimal 1 bukti nota sebelum realisasi');
        }
        if ($record->wajib_pengadaan && !in_array(strtoupper($kodePeran), ['PENGADAAN', 'SUPERADMIN'], true)) {
            $batas = $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
            abort(422, 'Pembelian senilai Rp ' . number_format($batas, 0, ',', '.') . ' ke atas wajib diproses oleh tim Pengadaan');
        }

        $hargaPerItem = [];
        foreach ($data['items'] as $item) {
            $hargaPerItem[$item['id_item']] = (float) $item['harga_aktual'];
        }
        $idItemTercatat = array_column($record->items, 'id_item');
        if (count($hargaPerItem) !== count($idItemTercatat) || array_diff($idItemTercatat, array_keys($hargaPerItem))) {
            abort(422, 'Harga aktual semua item wajib diisi');
        }

        return DB::transaction(function () use ($record, $data, $hargaPerItem, $idPerusahaan) {
            $this->repo->gantiHargaAktualItems($record->id_pembelian, $hargaPerItem);
            $items = $this->repo->listItems($record->id_pembelian);
            $totalAktual = array_sum(array_map(fn ($i) => ((int) $i->qty) * (float) $i->harga_aktual, $items));
            $statusRealisasi = $record->tanggal_pembayaran !== null ? self::STATUS_LUNAS : self::STATUS_DIBELI;
            $this->repo->updateHeader($record, [
                'status'            => $statusRealisasi,
                'tanggal_pembelian' => $data['tanggal_pembelian'],
                'total_aktual'      => $totalAktual,
            ]);
            $header = $this->repo->findById($record->id_pembelian);
            $this->repo->tambahStokDanMutasi($header, $items);
            $this->arusKasService->sinkronNominalPengajuanPembelian($record->id_pembelian, $totalAktual);
            return $this->findOrFail($record->id_pembelian, $idPerusahaan);
        });
    }

    public function laporan(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        return $this->repo->laporan($idPerusahaan, $dari, $sampai);
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }
}
