<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\PembelianSparepart\Contracts\PembelianSparepartRepositoryInterface;
use App\Modules\PembelianSparepart\Events\PembelianSparepartDirealisasi;
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

    public function batasMandiri(string $idPerusahaan): float
    {
        return $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
    }

    private function pastikanDiBawahBatasPembuatan(float $totalEstimasi, string $idPerusahaan): void
    {
        $batas = $this->batasMandiri($idPerusahaan);
        if (self::melebihiBatas($totalEstimasi, $batas)) {
            abort(422, 'Pembelian di atas Rp ' . number_format($batas, 0, ',', '.') . ' wajib diajukan lewat Permintaan Pembelian (PR)');
        }
    }

    private static function dariPermintaan(object $record): bool
    {
        return ($record->id_permintaan_pembelian ?? null) !== null;
    }

    private function pastikanBukanDariPermintaan(object $record): void
    {
        if (self::dariPermintaan($record)) {
            abort(422, 'Pembelian yang lahir dari PR tidak bisa diubah atau dihapus, batalkan PR-nya');
        }
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
        $this->pastikanDiBawahBatasPembuatan((float) $header['total_estimasi'], $idPerusahaan);
        return DB::transaction(function () use ($header, $items, $bukti, $idPerusahaan) {
            $header['id_perusahaan']   = $idPerusahaan;
            $header['status']          = self::STATUS_DIAJUKAN;
            $header['nomor_pengajuan'] = $this->repo->nomorBerikutnya($idPerusahaan);
            $record = $this->repo->createWithItems($header, $items);
            $this->simpanBukti($record->id_pembelian, $bukti);
            $hasil = $this->findOrFail($record->id_pembelian, $idPerusahaan);
            $this->arusKasService->buatPengajuanPembelianOtomatis($hasil, (float) $hasil->total_estimasi);
            return $hasil;
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBukanDariPermintaan($record);
        $this->pastikanBolehDiubah($record);
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        $this->pastikanDiBawahBatasPembuatan((float) $header['total_estimasi'], $idPerusahaan);
        return DB::transaction(function () use ($record, $header, $items, $idPerusahaan) {
            $this->repo->updateWithItems($record, $header, $items);
            $hasil = $this->findOrFail($record->id_pembelian, $idPerusahaan);
            $this->sinkronArusKasSetelahUpdate($record, $hasil);
            return $hasil;
        });
    }

    private function bolehDiubahAtauDihapus(object $record): bool
    {
        return $record->status === self::STATUS_DIAJUKAN;
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
        $this->arusKasService->buatPengajuanPembelianOtomatis($sesudah, (float) $sesudah->total_estimasi);
        $this->arusKasService->sinkronNominalPengajuanPembelian($sesudah->id_pembelian, (float) $sesudah->total_estimasi);
        $this->arusKasService->sinkronPerawatanPengajuanPembelian($sesudah->id_pembelian, $sesudah->id_perawatan);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBukanDariPermintaan($record);
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

    public function realisasi(string $id, array $data, string $idPerusahaan, string $kodePeran, string $idPengguna = ''): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_FINANCE], 'Realisasi hanya bisa dilakukan setelah disetujui finance');
        if (count($record->bukti) === 0) {
            abort(422, 'Unggah minimal 1 bukti nota sebelum realisasi');
        }
        $peranPengadaan = in_array(strtoupper($kodePeran), ['PENGADAAN', 'SUPERADMIN'], true);
        $dariPermintaan = self::dariPermintaan($record);
        if ($dariPermintaan && !$peranPengadaan) {
            abort(422, 'Realisasi pembelian dari PR hanya bisa dilakukan tim Pengadaan');
        }
        if ($record->wajib_pengadaan && !$peranPengadaan) {
            $batas = $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
            abort(422, 'Pembelian senilai Rp ' . number_format($batas, 0, ',', '.') . ' — pembelian di atas nilai ini wajib diproses oleh tim Pengadaan');
        }
        $idSupplier = !empty($data['id_supplier']) ? (string) $data['id_supplier'] : null;
        if ($idSupplier !== null && !$this->repo->supplierMilik($idPerusahaan, $idSupplier)) {
            abort(422, 'Supplier tidak ditemukan');
        }

        $hargaPerItem = [];
        foreach ($data['items'] as $item) {
            $hargaPerItem[$item['id_item']] = (float) $item['harga_aktual'];
        }
        $idItemTercatat = array_column($record->items, 'id_item');
        if (count($hargaPerItem) !== count($idItemTercatat) || array_diff($idItemTercatat, array_keys($hargaPerItem))) {
            abort(422, 'Harga aktual semua item wajib diisi');
        }

        return $this->realisasiInti($record, $hargaPerItem, (string) $data['tanggal_pembelian'], $idSupplier, $idPerusahaan, $dariPermintaan, $idPengguna);
    }

    public function salinBuktiDariPermintaan(string $idPembelian, array $bukti): void
    {
        $sudahAda = array_map(fn ($b) => (string) $b->url_file, $this->repo->listBukti($idPembelian));
        foreach ($bukti as $baris) {
            $urlFile = (string) $baris['url_file'];
            if (in_array($urlFile, $sudahAda, true)) {
                continue;
            }
            $this->repo->insertBukti([
                'id_pembelian' => $idPembelian,
                'url_file'     => $urlFile,
                'nama_asli'    => (string) $baris['nama_asli'],
            ]);
            $sudahAda[] = $urlFile;
        }
    }

    public function realisasiDariPermintaan(string $idPembelian, array $data, string $idPerusahaan, string $idPengguna): object
    {
        $record = $this->findOrFail($idPembelian, $idPerusahaan);
        if (!self::dariPermintaan($record)) {
            abort(422, 'Pembelian Sparepart ini tidak tertaut ke Permintaan Pembelian manapun');
        }
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_FINANCE], 'Realisasi hanya bisa dilakukan setelah disetujui finance');
        if (count($record->bukti) === 0) {
            abort(422, 'Unggah minimal 1 bukti nota sebelum realisasi');
        }
        $idSupplier = !empty($data['id_supplier']) ? (string) $data['id_supplier'] : null;
        if ($idSupplier !== null && !$this->repo->supplierMilik($idPerusahaan, $idSupplier)) {
            abort(422, 'Supplier tidak ditemukan');
        }

        $petaHarga = $data['harga_per_item_permintaan'];
        $hargaPerItem = [];
        foreach ($record->items as $item) {
            $idItemPermintaan = (string) ($item->id_item_permintaan ?? '');
            if ($idItemPermintaan === '' || !array_key_exists($idItemPermintaan, $petaHarga)) {
                abort(422, 'Harga aktual semua item wajib diisi');
            }
            $hargaPerItem[$item->id_item] = (float) $petaHarga[$idItemPermintaan];
        }

        return $this->realisasiInti($record, $hargaPerItem, (string) $data['tanggal_pembelian'], $idSupplier, $idPerusahaan, true, $idPengguna);
    }

    private function realisasiInti(object $record, array $hargaPerItem, string $tanggalPembelian, ?string $idSupplier, string $idPerusahaan, bool $dariPermintaan, string $idPengguna): object
    {
        return DB::transaction(function () use ($record, $hargaPerItem, $tanggalPembelian, $idSupplier, $idPerusahaan, $dariPermintaan, $idPengguna) {
            $terkunci = $this->repo->findByIdForUpdate($record->id_pembelian);
            if ($terkunci === null) {
                abort(404, 'Pengajuan pembelian tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_DISETUJUI_FINANCE], 'Realisasi hanya bisa dilakukan setelah disetujui finance');
            $this->repo->gantiHargaAktualItems($record->id_pembelian, $hargaPerItem);
            $items = $this->repo->listItems($record->id_pembelian);
            $totalAktual = array_sum(array_map(fn ($i) => ((int) $i->qty) * (float) $i->harga_aktual, $items));
            $statusRealisasi = $record->tanggal_pembayaran !== null ? self::STATUS_LUNAS : self::STATUS_DIBELI;
            $perubahan = [
                'status'            => $statusRealisasi,
                'tanggal_pembelian' => $tanggalPembelian,
                'total_aktual'      => $totalAktual,
            ];
            if ($idSupplier !== null) {
                $perubahan['id_supplier'] = $idSupplier;
            }
            $this->repo->updateHeader($record, $perubahan);
            $header = $this->repo->findById($record->id_pembelian);
            $this->repo->tambahStokDanMutasi($header, $items);
            if ($dariPermintaan) {
                event(new PembelianSparepartDirealisasi(
                    $idPerusahaan,
                    (string) $record->id_pembelian,
                    (string) $record->id_permintaan_pembelian,
                    $idPengguna
                ));
            } else {
                $this->arusKasService->sinkronNominalPengajuanPembelian($record->id_pembelian, $totalAktual);
            }
            return $this->findOrFail($record->id_pembelian, $idPerusahaan);
        }, 3);
    }

    public function buatDariPermintaan(object $pr, string $idPengguna): object
    {
        $idPerusahaan = (string) $pr->id_perusahaan;
        $items = [];
        foreach ($pr->items ?? [] as $item) {
            $item = (object) $item;
            $items[] = [
                'id_sparepart'       => (string) $item->id_sparepart,
                'nama_sparepart'     => (string) $item->nama_item,
                'qty'                => (int) $item->qty,
                'harga_estimasi'     => (float) $item->harga_estimasi,
                'id_item_permintaan' => (string) $item->id_item,
            ];
        }
        if ($items === []) {
            abort(422, 'PR spare part tidak memiliki item untuk dibuatkan Pembelian Sparepart');
        }

        return DB::transaction(function () use ($pr, $items, $idPerusahaan, $idPengguna) {
            $sekarang = now();
            $header = [
                'id_perusahaan'           => $idPerusahaan,
                'nomor_pengajuan'         => $this->repo->nomorBerikutnya($idPerusahaan),
                'id_supplier'             => null,
                'id_perawatan'            => !empty($pr->id_perawatan) ? (string) $pr->id_perawatan : null,
                'id_permintaan_pembelian' => (string) $pr->id_permintaan,
                'status'                  => self::STATUS_DISETUJUI_FINANCE,
                'disetujui_manager_oleh'  => $idPengguna,
                'disetujui_manager_pada'  => $sekarang,
                'disetujui_finance_oleh'  => $idPengguna,
                'disetujui_finance_pada'  => $sekarang,
                'tanggal_pengajuan'       => $sekarang->toDateString(),
                'keterangan'              => "Dari {$pr->nomor_permintaan}: {$pr->judul}",
                'total_estimasi'          => (float) $pr->total_estimasi,
            ];
            $record = $this->repo->createWithItems($header, $items);
            foreach ($pr->bukti_mentah ?? [] as $bukti) {
                $bukti = (object) $bukti;
                $this->repo->insertBukti([
                    'id_pembelian' => $record->id_pembelian,
                    'url_file'     => (string) $bukti->url_file,
                    'nama_asli'    => (string) $bukti->nama_asli,
                ]);
            }
            return $this->findOrFail($record->id_pembelian, $idPerusahaan);
        });
    }

    public function batalkanDariPermintaan(string $idPembelian, string $alasan, string $idPerusahaan): void
    {
        $record = $this->repo->findByIdForUpdate($idPembelian);
        if ($record === null || (string) $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pengajuan pembelian tidak ditemukan');
        }
        if (in_array($record->status, [self::STATUS_DIBELI, self::STATUS_LUNAS], true)) {
            abort(409, "Pembelian Sparepart {$record->nomor_pengajuan} sudah direalisasi, PR tidak bisa dibatalkan");
        }
        if ($record->status !== self::STATUS_DISETUJUI_FINANCE) {
            return;
        }
        $this->repo->updateHeader($record, [
            'status'         => self::STATUS_DITOLAK,
            'alasan_ditolak' => "PR dibatalkan: {$alasan}",
        ]);
    }

    public function laporan(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        return $this->repo->laporan($idPerusahaan, $dari, $sampai);
    }

    /** @return array{pembelian: object[], items: object[]} */
    public function dataLaporanLangsung(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        $pembelian = $this->repo->langsungUntukLaporan($idPerusahaan, $dari, $sampai);
        $idPembelian = array_map(fn ($r) => (string) $r->id_pembelian, $pembelian);
        return [
            'pembelian' => $pembelian,
            'items'     => $idPembelian !== [] ? $this->repo->itemsLangsungUntukLaporan($idPembelian) : [],
        ];
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }
}
