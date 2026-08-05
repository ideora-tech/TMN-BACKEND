<?php
declare(strict_types=1);

namespace App\Modules\PembelianSparepart;

use App\Modules\PembelianSparepart\Contracts\PembelianSparepartRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Support\Facades\DB;

class PembelianSparepartService
{
    public const STATUS_DIAJUKAN          = 'diajukan';
    public const STATUS_DISETUJUI_MANAGER = 'disetujui_manager';
    public const STATUS_DISETUJUI_FINANCE = 'disetujui_finance';
    public const STATUS_DITOLAK           = 'ditolak';
    public const STATUS_DIBELI            = 'dibeli';
    public const STATUS_LUNAS             = 'lunas';

    public function __construct(private readonly PembelianSparepartRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, int $page, int $limit, array $filter = []): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $filter);
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
        return $record;
    }

    public function create(array $data, string $idPerusahaan): object
    {
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        return DB::transaction(function () use ($header, $items, $idPerusahaan) {
            $header['id_perusahaan']   = $idPerusahaan;
            $header['status']          = self::STATUS_DIAJUKAN;
            $header['nomor_pengajuan'] = $this->repo->nomorBerikutnya($idPerusahaan);
            $record = $this->repo->createWithItems($header, $items);
            return $this->findOrFail($record->id_pembelian, $idPerusahaan);
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN], 'Pengajuan hanya bisa diubah saat status diajukan');
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        return DB::transaction(function () use ($record, $header, $items, $idPerusahaan) {
            $this->repo->updateWithItems($record, $header, $items);
            return $this->findOrFail($record->id_pembelian, $idPerusahaan);
        });
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN], 'Pengajuan hanya bisa dihapus saat status diajukan');
        $this->repo->softDelete($record);
    }

    private function susunHeaderItems(array $data, string $idPerusahaan): array
    {
        if (!$this->repo->supplierMilik($idPerusahaan, $data['id_supplier'])) {
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
            'id_supplier'       => $data['id_supplier'],
            'id_perawatan'      => $data['id_perawatan'] ?? null,
            'tanggal_pengajuan' => $data['tanggal_pengajuan'],
            'keterangan'        => $data['keterangan'] ?? null,
            'total_estimasi'    => $total,
        ];
        return [$header, $items];
    }

    public function approveManager(string $id, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN], 'Pengajuan tidak bisa disetujui manager');
        $this->repo->updateHeader($record, [
            'status'                 => self::STATUS_DISETUJUI_MANAGER,
            'disetujui_manager_oleh' => auth()->id(),
            'disetujui_manager_pada' => now(),
        ]);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function approveFinance(string $id, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_MANAGER], 'Pengajuan harus disetujui manager terlebih dulu');
        $this->repo->updateHeader($record, [
            'status'                 => self::STATUS_DISETUJUI_FINANCE,
            'disetujui_finance_oleh' => auth()->id(),
            'disetujui_finance_pada' => now(),
        ]);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function tolak(string $id, string $alasan, string $kodePeran, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $bolehManager = in_array($kodePeran, ['MANAGER', 'ADMIN', 'SUPERADMIN'], true);
        $bolehFinance = in_array($kodePeran, ['KEUANGAN', 'ADMIN', 'SUPERADMIN'], true);

        if ($record->status === self::STATUS_DIAJUKAN && !$bolehManager) {
            abort(422, 'Pengajuan status diajukan hanya bisa ditolak oleh manager');
        }
        if ($record->status === self::STATUS_DISETUJUI_MANAGER && !$bolehFinance) {
            abort(422, 'Pengajuan status disetujui manager hanya bisa ditolak oleh finance');
        }
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN, self::STATUS_DISETUJUI_MANAGER], 'Pengajuan tidak bisa ditolak');

        $this->repo->updateHeader($record, [
            'status'         => self::STATUS_DITOLAK,
            'alasan_ditolak' => $alasan,
        ]);
        return $this->findOrFail($id, $idPerusahaan);
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
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_FINANCE, self::STATUS_DIBELI], 'Bukti hanya bisa diunggah setelah disetujui finance');
        foreach ($files as $file) {
            $this->repo->insertBukti([
                'id_pembelian' => $id,
                'url_file'     => PenyimpananBerkas::simpan($file, 'pembelian-sparepart'),
                'nama_asli'    => $file->getClientOriginalName(),
            ]);
        }
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function hapusBukti(string $id, string $idBukti, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_FINANCE, self::STATUS_DIBELI], 'Bukti tidak bisa dihapus pada status ini');
        $bukti = $this->repo->findBukti($id, $idBukti);
        if ($bukti === null) {
            abort(404, 'Bukti tidak ditemukan');
        }
        $this->repo->softDeleteBukti($idBukti);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function realisasi(string $id, array $data, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI_FINANCE], 'Realisasi hanya bisa dilakukan setelah disetujui finance');
        if (count($record->bukti) === 0) {
            abort(422, 'Unggah minimal 1 bukti nota sebelum realisasi');
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
            $this->repo->updateHeader($record, [
                'status'            => self::STATUS_DIBELI,
                'tanggal_pembelian' => $data['tanggal_pembelian'],
                'total_aktual'      => $totalAktual,
            ]);
            $header = $this->repo->findById($record->id_pembelian);
            $this->repo->tambahStokDanMutasi($header, $items);
            return $this->findOrFail($record->id_pembelian, $idPerusahaan);
        });
    }

    public function tandaiLunas(string $id, string $tanggalPembayaran, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIBELI], 'Hanya pembelian berstatus dibeli yang bisa ditandai lunas');
        $this->repo->updateHeader($record, [
            'status'             => self::STATUS_LUNAS,
            'tanggal_pembayaran' => $tanggalPembayaran,
        ]);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function laporan(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        return $this->repo->laporan($idPerusahaan, $dari, $sampai);
    }
}
