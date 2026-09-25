<?php

declare(strict_types=1);

namespace App\Modules\PermintaanPembelian;

use App\Modules\Approval\ApprovalService;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\Barang\BarangService;
use App\Modules\Notifikasi\NotifikasiService;
use App\Modules\PembelianSparepart\PembelianSparepartService;
use App\Modules\PermintaanPembelian\Contracts\PermintaanPembelianRepositoryInterface;
use App\Support\KodeOtomatis;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PermintaanPembelianService
{
    public const STATUS_DIAJUKAN          = 'diajukan';
    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';
    public const STATUS_DISETUJUI         = 'disetujui';
    public const STATUS_DITOLAK           = 'ditolak';
    public const STATUS_DIPROSES          = 'diproses';
    public const STATUS_DIBELI            = 'dibeli';
    public const STATUS_DITERIMA          = 'diterima';
    public const STATUS_SELESAI           = 'selesai';
    public const STATUS_DIBATALKAN        = 'dibatalkan';

    public const KODE_APPROVAL      = 'permintaan_pembelian';
    public const KODE_APPROVAL_ASET = 'permintaan_pembelian_aset';
    public const TIPE               = ['umum', 'sparepart', 'aset'];
    public const TIPE_UMUM          = 'umum';
    public const TIPE_SPAREPART     = 'sparepart';
    public const TIPE_ASET          = 'aset';
    public const JENIS_ITEM         = ['barang', 'jasa', 'sparepart', 'aset'];

    public const PERAN_PENGADAAN = ['PENGADAAN', 'SUPERADMIN'];
    public const PERAN_KELOLA    = ['PENGADAAN', 'SUPERADMIN', 'ADMIN'];
    public const PERAN_LAPORAN   = ['SUPERADMIN', 'ADMIN', 'MANAGER', 'PENGADAAN', 'KEUANGAN'];

    public const LABEL_TIPE = ['umum' => 'Umum', 'sparepart' => 'Spare Part', 'aset' => 'Aset'];

    private const STATUS_BOLEH_UBAH = [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DISETUJUI, self::STATUS_DITOLAK];

    public function __construct(
        private readonly PermintaanPembelianRepositoryInterface $repo,
        private readonly BarangService $barangService,
        private readonly ApprovalService $approvalService,
        private readonly ArusKasService $arusKasService,
        private readonly NotifikasiService $notifikasiService,
        private readonly PembelianSparepartService $pembelianSparepartService,
    ) {}

    public function list(string $idPerusahaan, int $page, int $limit, array $filter): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $filter);
        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
                'ringkasan'  => $this->repo->ringkasanStatus($idPerusahaan),
            ],
        ];
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Permintaan pembelian tidak ditemukan');
        }
        $record->items = $this->repo->listItems($id);
        $record->bukti = array_map(fn ($b) => [
            'id_bukti'  => $b->id_bukti,
            'tahap'     => $b->tahap,
            'url_file'  => PenyimpananBerkas::url($b->url_file),
            'nama_asli' => $b->nama_asli,
        ], $this->repo->listBukti($id));
        $record->pengajuan_keuangan = self::tipeAset($record) ? null : $this->arusKasService->infoPengajuanPermintaanPembelian($id);
        $record->pembelian_sparepart = $this->repo->pembelianSparepartDariPermintaan($id);
        $record->termin = $this->repo->listTermin($id);
        foreach ($record->termin as $termin) {
            $termin->pengajuan = $termin->id_pengajuan !== null
                ? $this->arusKasService->infoPengajuanTerminPembelian((string) $termin->id_pengajuan)
                : null;
        }
        $record->termin_lunas = $record->termin !== [] && !$this->repo->adaTerminMenunggu($id);
        if (self::tipeSparepart($record)) {
            $batas = $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
            $record->batas_mandiri = $batas;
            $record->boleh_realisasi_mandiri = (float) $record->total_estimasi <= $batas;
        } else {
            $record->batas_mandiri = null;
            $record->boleh_realisasi_mandiri = false;
        }
        return $record;
    }

    /** @param UploadedFile[] $bukti */
    public function create(array $data, array $bukti, string $idPerusahaan, string $idPengguna): object
    {
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        return DB::transaction(function () use ($header, $items, $bukti, $idPerusahaan, $idPengguna) {
            $header['id_perusahaan']    = $idPerusahaan;
            $header['id_pengaju']       = $idPengguna;
            $header['nomor_permintaan'] = KodeOtomatis::berikutnya($idPerusahaan, 'permintaan_pembelian');
            $header['status']           = self::STATUS_DIAJUKAN;
            $record = $this->repo->createWithItems($header, $items);
            $this->simpanBukti($record->id_permintaan, $bukti, 'pengajuan');
            $status = $this->ajukanApproval($record, false);
            $this->repo->updateHeader($record, ['status' => $status]);
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            if ($status === self::STATUS_DISETUJUI) {
                if ($this->mandiriSparepart($hasil, $idPerusahaan)) {
                    $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} disetujui", 'Nilainya di bawah batas mandiri, silakan beli lalu catat realisasi');
                } else {
                    $this->notifikasiKePengadaan($hasil, "PR {$hasil->nomor_permintaan} siap diproses", $hasil->judul);
                }
            }
            return $hasil;
        });
    }

    public function update(string $id, array $data, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $pesanStatus = 'Permintaan hanya bisa diubah sebelum diproses Pengadaan';
        $this->pastikanStatus($record, self::STATUS_BOLEH_UBAH, $pesanStatus);
        $this->pastikanPengajuAtauKelola($record, $idPengguna, $kodePeran);
        [$header, $items] = $this->susunHeaderItems($data, $idPerusahaan);
        return DB::transaction(function () use ($record, $header, $items, $idPerusahaan, $pesanStatus) {
            $this->kunciDanPastikanStatus($record->id_permintaan, self::STATUS_BOLEH_UBAH, $pesanStatus);
            $this->repo->updateWithItems($record, $header, $items);
            $segar = $this->repo->findById($record->id_permintaan);
            $status = $this->ajukanApproval($segar, true);
            $this->repo->updateHeader($segar, ['status' => $status, 'alasan_ditolak' => null]);
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            if ($status === self::STATUS_DISETUJUI) {
                if ($this->mandiriSparepart($hasil, $idPerusahaan)) {
                    $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} disetujui", 'Nilainya di bawah batas mandiri, silakan beli lalu catat realisasi');
                } else {
                    $this->notifikasiKePengadaan($hasil, "PR {$hasil->nomor_permintaan} diperbarui & siap diproses", $hasil->judul);
                }
            }
            return $hasil;
        });
    }

    public function delete(string $id, string $idPerusahaan, string $idPengguna, string $kodePeran): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $pesanStatus = 'Permintaan hanya bisa dihapus sebelum diproses Pengadaan';
        $this->pastikanStatus($record, self::STATUS_BOLEH_UBAH, $pesanStatus);
        $this->pastikanPengajuAtauKelola($record, $idPengguna, $kodePeran);
        DB::transaction(function () use ($record, $idPerusahaan, $pesanStatus) {
            $this->kunciDanPastikanStatus($record->id_permintaan, self::STATUS_BOLEH_UBAH, $pesanStatus);
            $this->approvalService->batalkanUntukReferensi([self::KODE_APPROVAL, self::KODE_APPROVAL_ASET], $record->id_permintaan, $idPerusahaan);
            $this->repo->softDelete($record);
        });
    }

    public function proses(string $id, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanPengadaan($kodePeran);
        $pesanStatus = 'Permintaan hanya bisa diproses setelah disetujui';
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI], $pesanStatus);
        return DB::transaction(function () use ($record, $idPerusahaan, $idPengguna, $pesanStatus) {
            $this->kunciDanPastikanStatus($record->id_permintaan, [self::STATUS_DISETUJUI], $pesanStatus);
            if (!self::tipeSparepart($record)) {
                $this->repo->updateHeader($record, [
                    'status'        => self::STATUS_DIPROSES,
                    'diproses_oleh' => $idPengguna,
                    'diproses_pada' => now(),
                ]);
                $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
                $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} sedang diproses Pengadaan", $hasil->judul);
                return $hasil;
            }
            $ps = $this->mulaiProsesSparepart($record, $idPerusahaan, $idPengguna);
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} sedang diproses Pengadaan (PS {$ps->nomor_pengajuan})", $hasil->judul);
            return $hasil;
        });
    }

    private function mulaiProsesSparepart(object $record, string $idPerusahaan, string $idPengguna): object
    {
        $this->repo->updateHeader($record, [
            'status'        => self::STATUS_DIPROSES,
            'diproses_oleh' => $idPengguna,
            'diproses_pada' => now(),
        ]);
        $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
        $hasil->bukti_mentah = $this->repo->listBuktiMentah($record->id_permintaan);
        return $this->pembelianSparepartService->buatDariPermintaan($hasil, $idPengguna);
    }

    public function dibeli(string $id, array $data, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBukanSparepart($record);
        $this->pastikanPengadaan($kodePeran);
        $pesanStatus = 'Tandai Dibeli hanya bisa dilakukan saat permintaan sedang diproses';
        $this->pastikanStatus($record, [self::STATUS_DIPROSES], $pesanStatus);

        $supplier = $this->repo->supplierMilik($idPerusahaan, (string) $data['id_supplier']);
        if ($supplier === null) {
            abort(422, 'Supplier tidak ditemukan');
        }
        if (count(array_filter($record->bukti, fn ($b) => $b['tahap'] === 'pembelian')) === 0) {
            abort(422, 'Unggah minimal 1 nota/PO supplier (tahap pembelian) sebelum menandai dibeli');
        }

        $itemTercatat = [];
        foreach ($record->items as $item) {
            $itemTercatat[$item->id_item] = $item;
        }
        $masuk = [];
        foreach ($data['items'] as $baris) {
            if (!isset($itemTercatat[$baris['id_item']])) {
                abort(422, 'Item tidak ditemukan pada permintaan ini');
            }
            $masuk[$baris['id_item']] = $baris;
        }
        if (count($masuk) !== count($itemTercatat)) {
            abort(422, 'Harga aktual semua item wajib diisi');
        }

        $idBarangDipakai = [];
        foreach ($itemTercatat as $idItem => $item) {
            $idBarang = $masuk[$idItem]['id_barang'] ?? $item->id_barang;
            if ($item->jenis === 'barang') {
                if ($idBarang === null || $idBarang === '') {
                    abort(422, "Item \"{$item->nama_item}\" belum ditautkan ke Master Barang");
                }
                $idBarangDipakai[] = $idBarang;
            }
        }
        $barangMap = $this->barangService->pastikanMilik($idBarangDipakai, $idPerusahaan);

        $totalAktual = 0.0;
        foreach ($itemTercatat as $idItem => $item) {
            $totalAktual += (int) $item->qty * (float) $masuk[$idItem]['harga_aktual'];
        }
        $termin = self::tipeAset($record) ? $this->susunTermin($data['termin'] ?? [], $totalAktual) : [];

        return DB::transaction(function () use ($record, $data, $itemTercatat, $masuk, $barangMap, $idPerusahaan, $idPengguna, $supplier, $pesanStatus, $totalAktual, $termin) {
            $this->kunciDanPastikanStatus($record->id_permintaan, [self::STATUS_DIPROSES], $pesanStatus);
            foreach ($itemTercatat as $idItem => $item) {
                $harga = (float) $masuk[$idItem]['harga_aktual'];
                $idBarang = $item->jenis === 'barang' ? ($masuk[$idItem]['id_barang'] ?? $item->id_barang) : null;
                $ubah = ['harga_aktual' => $harga];
                if ($idBarang !== null) {
                    $ubah['id_barang'] = $idBarang;
                    $ubah['nama_item'] = $barangMap[$idBarang]->nama;
                    $this->barangService->perbaruiHargaStandar($idBarang, $harga);
                }
                $this->repo->updateItem($idItem, $ubah);
            }
            $this->repo->updateHeader($record, [
                'status'            => self::STATUS_DIBELI,
                'id_supplier'       => $supplier->id_supplier,
                'total_aktual'      => $totalAktual,
                'tanggal_pembelian' => $data['tanggal_pembelian'],
                'dibeli_oleh'       => $idPengguna,
                'dibeli_pada'       => now(),
            ]);
            if (self::tipeAset($record)) {
                $terminTersimpan = [];
                foreach ($termin as $baris) {
                    $baris['id_termin'] = $this->repo->insertTermin([
                        'id_permintaan' => $record->id_permintaan,
                        'urutan'        => $baris['urutan'],
                        'nama'          => $baris['nama'],
                        'nominal'       => $baris['nominal'],
                        'jatuh_tempo'   => $baris['jatuh_tempo'],
                        'status'        => 'menunggu',
                    ]);
                    $terminTersimpan[] = $baris;
                }
                $petaPengajuan = $this->arusKasService->buatPengajuanTerminPermintaanPembelian(
                    $record->id_permintaan,
                    $idPerusahaan,
                    $record->nomor_permintaan,
                    (string) $supplier->nama,
                    $terminTersimpan,
                );
                foreach ($petaPengajuan as $idTermin => $idPengajuan) {
                    $this->repo->setPengajuanTermin((string) $idTermin, (string) $idPengajuan);
                }
                $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
                $jumlahTermin = count($terminTersimpan);
                $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} sudah dibeli, {$jumlahTermin} termin pembayaran dibuat", 'Daftarkan setiap unit ke master Armada setelah diterima');
                return $hasil;
            }
            $this->arusKasService->buatPengajuanPermintaanPembelianOtomatis(
                $record->id_permintaan,
                $idPerusahaan,
                $record->nomor_permintaan,
                $totalAktual,
                (string) $supplier->nama,
            );
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} sudah dibeli", 'Konfirmasi penerimaan setelah barang/jasa diterima');
            return $hasil;
        });
    }

    private function susunTermin(array $masuk, float $totalAktual): array
    {
        if ($masuk === []) {
            abort(422, 'Daftar termin pembayaran wajib diisi untuk PR aset');
        }
        $termin = [];
        $jumlah = 0.0;
        $urutan = 0;
        foreach ($masuk as $baris) {
            $urutan++;
            $nominal = (float) $baris['nominal'];
            $jumlah += $nominal;
            $termin[] = [
                'urutan'      => $urutan,
                'nama'        => trim((string) $baris['nama']),
                'nominal'     => $nominal,
                'jatuh_tempo' => !empty($baris['jatuh_tempo']) ? $baris['jatuh_tempo'] : null,
            ];
        }
        if (abs($jumlah - $totalAktual) > 0.01) {
            $total = number_format($totalAktual, 0, ',', '.');
            abort(422, "Total termin harus sama dengan total aktual (Rp {$total})");
        }
        return $termin;
    }

    public function terima(string $id, array $data, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanBukanSparepart($record);
        if (self::tipeAset($record)) {
            abort(422, 'PR aset diterima per unit lewat tombol Daftarkan Unit');
        }
        $pesanStatus = 'Penerimaan hanya bisa dikonfirmasi setelah ditandai dibeli';
        $this->pastikanStatus($record, [self::STATUS_DIBELI], $pesanStatus);
        $this->pastikanPengajuAtauPengadaan($record, $idPengguna, $kodePeran);

        $itemTercatat = [];
        foreach ($record->items as $item) {
            $itemTercatat[$item->id_item] = $item;
        }
        $masuk = [];
        foreach ($data['items'] as $baris) {
            $item = $itemTercatat[$baris['id_item']] ?? null;
            if ($item === null) {
                abort(422, 'Item tidak ditemukan pada permintaan ini');
            }
            if ((int) $baris['qty_diterima'] > (int) $item->qty) {
                abort(422, "Qty diterima \"{$item->nama_item}\" melebihi qty permintaan");
            }
            $masuk[$baris['id_item']] = (int) $baris['qty_diterima'];
        }
        if (count($masuk) !== count($itemTercatat)) {
            abort(422, 'Qty diterima semua item wajib diisi');
        }

        return DB::transaction(function () use ($record, $data, $itemTercatat, $masuk, $idPerusahaan, $idPengguna, $pesanStatus) {
            $this->kunciDanPastikanStatus($record->id_permintaan, [self::STATUS_DIBELI], $pesanStatus);
            foreach ($itemTercatat as $idItem => $item) {
                $qty = $masuk[$idItem];
                $this->repo->updateItem($idItem, ['qty_diterima' => $qty]);
                if ($item->jenis === 'barang' && $qty > 0) {
                    $this->barangService->tambahStokDariPenerimaan(
                        (string) $item->id_barang, $qty, (float) $item->harga_aktual,
                        $record->id_permintaan, $record->nomor_permintaan, $data['tanggal_diterima'],
                    );
                }
            }
            $keterangan = trim((string) ($data['keterangan'] ?? ''));
            $this->repo->updateHeader($record, [
                'status'                => self::STATUS_DITERIMA,
                'tanggal_diterima'      => $data['tanggal_diterima'],
                'keterangan_penerimaan' => $keterangan !== '' ? $keterangan : null,
                'diterima_oleh'         => $idPengguna,
                'diterima_pada'         => now(),
            ]);
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            if ($hasil->id_pengaju !== $idPengguna) {
                $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} sudah diterima", 'Penerimaan barang/jasa telah dikonfirmasi Pengadaan');
            }
            $this->notifikasiKePengadaan($hasil, "PR {$hasil->nomor_permintaan} sudah diterima", 'Transfer pembayaran kini bisa diproses Keuangan', $idPengguna);
            return $hasil;
        });
    }

    public function realisasiSparepart(string $id, array $data, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        if (!self::tipeSparepart($record)) {
            abort(422, 'Realisasi spare part hanya untuk PR tipe spare part');
        }
        $pesanStatus = 'Realisasi hanya bisa dicatat setelah PR disetujui';
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI, self::STATUS_DIPROSES], $pesanStatus);

        $batas = $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
        $pengadaan = in_array(strtoupper($kodePeran), self::PERAN_PENGADAAN, true);
        $mandiri = (float) $record->total_estimasi <= $batas;
        if (!$pengadaan) {
            if (!$mandiri) {
                abort(422, 'PR di atas Rp ' . number_format($batas, 0, ',', '.') . ' direalisasi oleh tim Pengadaan');
            }
            if ($record->id_pengaju !== $idPengguna) {
                abort(422, 'Hanya pengaju atau tim Pengadaan yang bisa mencatat realisasi PR ini');
            }
        }

        $itemTercatat = [];
        foreach ($record->items as $item) {
            $itemTercatat[$item->id_item] = $item;
        }
        $masuk = [];
        foreach ($data['items'] as $baris) {
            if (!isset($itemTercatat[$baris['id_item']])) {
                abort(422, 'Item tidak ditemukan pada permintaan ini');
            }
            $masuk[$baris['id_item']] = (float) $baris['harga_aktual'];
        }
        if (count($masuk) !== count($itemTercatat)) {
            abort(422, 'Harga aktual semua item wajib diisi');
        }
        $totalAktual = 0.0;
        foreach ($itemTercatat as $idItem => $item) {
            $totalAktual += (int) $item->qty * $masuk[$idItem];
        }
        if (!$pengadaan && $totalAktual > $batas) {
            abort(422, 'Total aktual Rp ' . number_format($totalAktual, 0, ',', '.') . ' melebihi batas mandiri Rp ' . number_format($batas, 0, ',', '.') . ', realisasi harus oleh tim Pengadaan');
        }

        if (count(array_filter($record->bukti, fn ($b) => $b['tahap'] === 'pembelian')) === 0) {
            abort(422, 'Unggah minimal 1 nota pembelian sebelum mencatat realisasi');
        }

        $idSupplier = !empty($data['id_supplier']) ? (string) $data['id_supplier'] : null;
        if ($idSupplier !== null && $this->repo->supplierMilik($idPerusahaan, $idSupplier) === null) {
            abort(422, 'Supplier tidak ditemukan');
        }

        return DB::transaction(function () use ($record, $idPerusahaan, $idPengguna, $pesanStatus, $masuk, $data, $idSupplier) {
            $terkunci = $this->kunciDanPastikanStatus($record->id_permintaan, [self::STATUS_DISETUJUI, self::STATUS_DIPROSES], $pesanStatus);
            if ($terkunci->status === self::STATUS_DISETUJUI) {
                $this->mulaiProsesSparepart($terkunci, $idPerusahaan, $idPengguna);
            }
            $ps = $this->repo->pembelianSparepartDariPermintaan($record->id_permintaan);
            if ($ps === null) {
                abort(422, 'Pembelian Sparepart untuk permintaan ini tidak ditemukan');
            }
            $buktiPembelian = array_values(array_filter(
                $this->repo->listBukti($record->id_permintaan),
                fn ($b) => $b->tahap === 'pembelian'
            ));
            $buktiMentah = array_map(fn ($b) => ['url_file' => (string) $b->url_file, 'nama_asli' => (string) $b->nama_asli], $buktiPembelian);
            $this->pembelianSparepartService->salinBuktiDariPermintaan((string) $ps->id_pembelian, $buktiMentah);

            $this->pembelianSparepartService->realisasiDariPermintaan((string) $ps->id_pembelian, [
                'tanggal_pembelian'         => $data['tanggal_pembelian'],
                'id_supplier'               => $idSupplier,
                'harga_per_item_permintaan' => $masuk,
            ], $idPerusahaan, $idPengguna);

            return $this->findOrFail($record->id_permintaan, $idPerusahaan);
        }, 3);
    }

    public function batal(string $id, string $alasan, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $pengadaan = in_array(strtoupper($kodePeran), self::PERAN_PENGADAAN, true);
        $bolehStatus = $pengadaan
            ? [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DISETUJUI, self::STATUS_DIPROSES]
            : [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DISETUJUI];
        if (!$pengadaan && $record->id_pengaju !== $idPengguna) {
            abort(422, 'Hanya pengaju atau tim Pengadaan yang bisa membatalkan permintaan ini');
        }
        $pesanStatus = 'Permintaan tidak bisa dibatalkan pada status ini';
        $this->pastikanStatus($record, $bolehStatus, $pesanStatus);

        return DB::transaction(function () use ($record, $alasan, $idPerusahaan, $bolehStatus, $pesanStatus) {
            $this->kunciDanPastikanStatus($record->id_permintaan, $bolehStatus, $pesanStatus);
            if (self::tipeSparepart($record)) {
                $ps = $this->repo->pembelianSparepartDariPermintaan($record->id_permintaan);
                if ($ps !== null) {
                    $this->pembelianSparepartService->batalkanDariPermintaan((string) $ps->id_pembelian, $alasan, $idPerusahaan);
                }
            }
            $this->approvalService->batalkanUntukReferensi([self::KODE_APPROVAL, self::KODE_APPROVAL_ASET], $record->id_permintaan, $idPerusahaan);
            $this->arusKasService->hapusPengajuanPermintaanPembelian($record->id_permintaan);
            $this->repo->updateHeader($record, ['status' => self::STATUS_DIBATALKAN, 'alasan_batal' => $alasan]);
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            $this->notifikasiKePengaju($hasil, "PR {$hasil->nomor_permintaan} dibatalkan", $alasan);
            return $hasil;
        });
    }

    /** @param UploadedFile[] $files */
    public function tambahBukti(string $id, array $files, string $tahap, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [
            self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DISETUJUI, self::STATUS_DITOLAK,
            self::STATUS_DIPROSES, self::STATUS_DIBELI, self::STATUS_DITERIMA,
        ], 'Lampiran tidak bisa ditambahkan pada status ini');
        $this->pastikanPengajuAtauKelola($record, $idPengguna, $kodePeran);
        if (self::tipeSparepart($record)) {
            if ($tahap === 'penerimaan') {
                abort(422, 'PR spare part diterima otomatis saat realisasi');
            }
            if ($tahap === 'pembelian' && !in_array($record->status, [self::STATUS_DISETUJUI, self::STATUS_DIPROSES], true)) {
                abort(422, 'Nota pembelian hanya bisa diunggah sebelum realisasi');
            }
        }
        $this->simpanBukti($id, $files, $tahap);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function hapusBukti(string $id, string $idBukti, string $idPerusahaan, string $idPengguna, string $kodePeran): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $this->pastikanPengajuAtauKelola($record, $idPengguna, $kodePeran);
        if ($this->repo->findBukti($id, $idBukti) === null) {
            abort(404, 'Lampiran tidak ditemukan');
        }
        $this->repo->softDeleteBukti($idBukti);
        return $this->findOrFail($id, $idPerusahaan);
    }

    public function selesaikanDariPembelianSparepart(string $idPermintaan, string $idPembelian, string $idPerusahaan, string $idPengguna): void
    {
        $record = $this->findOrFail($idPermintaan, $idPerusahaan);
        if (!self::tipeSparepart($record)) {
            abort(422, "PR {$record->nomor_permintaan} bukan PR spare part");
        }
        $ps = $this->pembelianSparepartService->findOrFail($idPembelian, $idPerusahaan);
        if ((string) ($ps->id_permintaan_pembelian ?? '') !== (string) $record->id_permintaan) {
            abort(422, "Pembelian Sparepart {$ps->nomor_pengajuan} tidak tertaut ke PR {$record->nomor_permintaan}");
        }
        $pesanStatus = 'PR spare part hanya bisa ditutup otomatis saat sedang diproses';
        $this->pastikanStatus($record, [self::STATUS_DIPROSES], $pesanStatus);
        $pelaku = $idPengguna !== '' ? $idPengguna : null;

        DB::transaction(function () use ($record, $ps, $idPerusahaan, $pelaku, $pesanStatus) {
            $this->kunciDanPastikanStatus($record->id_permintaan, [self::STATUS_DIPROSES], $pesanStatus);
            $hargaAktualPs = [];
            foreach ($ps->items as $itemPs) {
                if (($itemPs->id_item_permintaan ?? null) !== null && $itemPs->harga_aktual !== null) {
                    $hargaAktualPs[(string) $itemPs->id_item_permintaan] = (float) $itemPs->harga_aktual;
                }
            }
            foreach ($record->items as $item) {
                if (!array_key_exists((string) $item->id_item, $hargaAktualPs)) {
                    abort(422, "Item PR {$item->nama_item} tidak punya pasangan pada Pembelian Sparepart {$ps->nomor_pengajuan}");
                }
                $this->repo->updateItem($item->id_item, [
                    'harga_aktual' => $hargaAktualPs[(string) $item->id_item],
                    'qty_diterima' => (int) $item->qty,
                ]);
            }
            $totalAktual = (float) $ps->total_aktual;
            $sekarang = now();
            $this->repo->updateHeader($record, [
                'status'                => self::STATUS_DITERIMA,
                'id_supplier'           => $ps->id_supplier,
                'total_aktual'          => $totalAktual,
                'tanggal_pembelian'     => $ps->tanggal_pembelian,
                'tanggal_diterima'      => $ps->tanggal_pembelian,
                'dibeli_oleh'           => $pelaku,
                'dibeli_pada'           => $sekarang,
                'diterima_oleh'         => $pelaku,
                'diterima_pada'         => $sekarang,
                'keterangan_penerimaan' => "Diterima otomatis via realisasi {$ps->nomor_pengajuan}",
            ]);
            $this->arusKasService->buatPengajuanPermintaanPembelianOtomatis(
                $record->id_permintaan,
                $idPerusahaan,
                $record->nomor_permintaan,
                $totalAktual,
                (string) ($ps->nama_supplier ?? ''),
                (string) $ps->id_pembelian,
            );
            $hasil = $this->findOrFail($record->id_permintaan, $idPerusahaan);
            $judul = "PR {$hasil->nomor_permintaan} sudah dibeli & diterima";
            $this->notifikasiKePengaju($hasil, $judul, "Direalisasi lewat Pembelian Sparepart {$ps->nomor_pengajuan}");
            $this->notifikasiKePengadaan($hasil, $judul, 'Transfer pembayaran kini bisa diproses Keuangan', $pelaku);
        });
    }

    public function infoPengajuanKeuangan(string $id, string $idPerusahaan): ?array
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        if (self::tipeAset($record)) {
            return null;
        }
        return $this->arusKasService->infoPengajuanPermintaanPembelian($id);
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    public function laporan(string $idPerusahaan, ?string $dari, ?string $sampai, ?string $tipe, string $kodePeran): array
    {
        $this->pastikanAksesLaporan($kodePeran);

        $prRows = collect($this->repo->laporanPermintaan($idPerusahaan, $dari, $sampai, $tipe));
        $idPr = $prRows->pluck('id_permintaan')->all();
        $prItems = collect($this->repo->itemsUntukLaporan($idPr));

        $psData = ($tipe === null || $tipe === self::TIPE_SPAREPART)
            ? $this->pembelianSparepartService->dataLaporanLangsung($idPerusahaan, $dari, $sampai)
            : ['pembelian' => [], 'items' => []];
        $psRows = collect($psData['pembelian']);
        $psItems = collect($psData['items']);

        $gabungan = $prRows->map(fn ($r) => (object) [
            'tipe'           => $r->tipe ?? self::TIPE_UMUM,
            'total_estimasi' => (float) $r->total_estimasi,
            'total_aktual'   => (float) $r->total_aktual,
            'tanggal'        => $r->tanggal_pembelian,
            'departemen'     => $r->nama_departemen ?? 'Tanpa Departemen',
            'supplier'       => $r->nama_supplier ?? 'Tanpa Supplier',
            'id_perawatan'   => $r->id_perawatan,
            'nopol'          => $r->nopol_perawatan ?? 'Tanpa Nopol',
        ])->concat($psRows->map(fn ($r) => (object) [
            'tipe'           => self::TIPE_SPAREPART,
            'total_estimasi' => (float) $r->total_estimasi,
            'total_aktual'   => (float) ($r->total_aktual ?? 0),
            'tanggal'        => $r->tanggal_pembelian,
            'departemen'     => 'Pembelian Langsung',
            'supplier'       => $r->nama_supplier ?? 'Tanpa Supplier',
            'id_perawatan'   => $r->id_perawatan,
            'nopol'          => $r->nopol_armada ?? 'Tanpa Nopol',
        ]));

        $ringkasan = [
            'total_estimasi' => (float) $gabungan->sum('total_estimasi'),
            'total_aktual'   => (float) $gabungan->sum('total_aktual'),
            'selisih'        => (float) $gabungan->sum('total_aktual') - (float) $gabungan->sum('total_estimasi'),
            'jumlah'         => $gabungan->count(),
        ];

        $perBulan = $gabungan->groupBy(fn ($r) => $r->tanggal !== null ? Carbon::parse($r->tanggal)->format('Y-m') : 'lainnya')
            ->map(fn ($grup, $bulan) => [
                'bulan'     => $bulan,
                'umum'      => (float) $grup->where('tipe', self::TIPE_UMUM)->sum('total_aktual'),
                'sparepart' => (float) $grup->where('tipe', self::TIPE_SPAREPART)->sum('total_aktual'),
                'aset'      => (float) $grup->where('tipe', self::TIPE_ASET)->sum('total_aktual'),
                'total'     => (float) $grup->sum('total_aktual'),
                'jumlah'    => $grup->count(),
            ])->sortBy('bulan')->values()->all();

        $perTipe = $gabungan->groupBy('tipe')
            ->map(fn ($grup, $tipeGrup) => [
                'tipe'         => $tipeGrup,
                'label'        => self::LABEL_TIPE[$tipeGrup] ?? ucfirst((string) $tipeGrup),
                'total_aktual' => (float) $grup->sum('total_aktual'),
                'jumlah'       => $grup->count(),
            ])->sortByDesc('total_aktual')->values()->all();

        $kategoriGabungan = $prItems->map(fn ($i) => (object) [
            'jenis' => $i->jenis, 'qty' => (int) $i->qty, 'harga_aktual' => $i->harga_aktual,
            'kategori_barang' => $i->kategori_barang, 'kategori_sparepart' => $i->kategori_sparepart,
            'nama_jenis_kendaraan' => $i->nama_jenis_kendaraan,
        ])->concat($psItems->map(fn ($i) => (object) [
            'jenis' => self::TIPE_SPAREPART, 'qty' => (int) $i->qty, 'harga_aktual' => $i->harga_aktual,
            'kategori_barang' => null, 'kategori_sparepart' => $i->kategori_sparepart,
            'nama_jenis_kendaraan' => null,
        ]));

        $perKategori = $kategoriGabungan->filter(fn ($i) => $i->harga_aktual !== null)
            ->groupBy(fn ($i) => match ($i->jenis) {
                'barang'    => 'Barang · ' . ($i->kategori_barang ?? 'Tanpa Kategori'),
                'jasa'      => 'Jasa',
                'sparepart' => 'Spare Part · ' . ($i->kategori_sparepart ?? 'Tanpa Kategori'),
                'aset'      => 'Aset · ' . ($i->nama_jenis_kendaraan ?? 'Lainnya'),
                default     => 'Lainnya',
            })
            ->map(fn ($grup, $kategori) => [
                'kategori'     => $kategori,
                'total_aktual' => (float) $grup->sum(fn ($i) => (int) $i->qty * (float) $i->harga_aktual),
            ])->sortByDesc('total_aktual')->values()->all();

        $perDepartemen = $gabungan->groupBy('departemen')
            ->map(fn ($grup, $nama) => ['departemen' => $nama, 'total_aktual' => (float) $grup->sum('total_aktual'), 'jumlah' => $grup->count()])
            ->sortByDesc('total_aktual')->values()->all();

        $perSupplier = $gabungan->groupBy('supplier')
            ->map(fn ($grup, $nama) => ['supplier' => $nama, 'total_aktual' => (float) $grup->sum('total_aktual'), 'jumlah' => $grup->count()])
            ->sortByDesc('total_aktual')->values()->all();

        $sparepartRows = $gabungan->where('tipe', self::TIPE_SPAREPART);
        $perArmada = $sparepartRows->filter(fn ($r) => $r->id_perawatan !== null)
            ->groupBy('nopol')
            ->map(fn ($grup, $nopol) => ['nopol' => $nopol, 'total_aktual' => (float) $grup->sum('total_aktual'), 'jumlah' => $grup->count()])
            ->sortByDesc('total_aktual')->values()->all();
        $tanpaArmadaRows = $sparepartRows->filter(fn ($r) => $r->id_perawatan === null);
        $sparepartTanpaArmada = [
            'total_aktual' => (float) $tanpaArmadaRows->sum('total_aktual'),
            'jumlah'       => $tanpaArmadaRows->count(),
        ];

        $asetRows = $prRows->where('tipe', self::TIPE_ASET)->values();
        $idAset = $asetRows->pluck('id_permintaan')->all();
        $terminAset = collect($this->repo->terminDitransferUntukPermintaan($idAset))
            ->groupBy('id_permintaan')->map(fn ($g) => (float) $g->sum('nominal'));
        $aset = $asetRows->map(function ($r) use ($prItems, $terminAset) {
            $itemsPr = $prItems->where('id_permintaan', $r->id_permintaan);
            $terbayar = (float) ($terminAset[$r->id_permintaan] ?? 0.0);
            $totalAktual = (float) $r->total_aktual;
            return [
                'id_permintaan'     => $r->id_permintaan,
                'nomor_permintaan'  => $r->nomor_permintaan,
                'judul'             => $r->judul,
                'tanggal_pembelian' => $r->tanggal_pembelian,
                'unit_total'        => (int) $itemsPr->sum('qty'),
                'unit_terdaftar'    => (int) $itemsPr->sum(fn ($i) => (int) ($i->qty_diterima ?? 0)),
                'total_aktual'      => $totalAktual,
                'terbayar'          => $terbayar,
                'sisa'              => $totalAktual - $terbayar,
                'status'            => $r->status,
            ];
        })->values()->all();

        $menungguRows = collect($this->repo->menungguDiprosesLaporan($idPerusahaan, $tipe));
        $sekarang = now()->startOfDay();
        $menungguLama = $menungguRows->map(fn ($r) => [
            'id_permintaan'      => $r->id_permintaan,
            'nomor_permintaan'   => $r->nomor_permintaan,
            'judul'              => $r->judul,
            'tipe'               => $r->tipe ?? self::TIPE_UMUM,
            'status'             => $r->status,
            'tanggal_permintaan' => $r->tanggal_permintaan,
            'umur_hari'          => max(0, (int) Carbon::parse($r->tanggal_permintaan)->diffInDays($sekarang)),
            'username_pengaju'   => $r->username_pengaju,
        ])->sortByDesc('umur_hari')->take(20)->values()->all();

        $leadRows = $prRows->filter(fn ($r) => $r->tanggal_diterima !== null)
            ->map(fn ($r) => (object) [
                'tipe' => $r->tipe ?? self::TIPE_UMUM,
                'hari' => max(0, (int) Carbon::parse($r->tanggal_permintaan)->diffInDays(Carbon::parse($r->tanggal_diterima))),
            ]);
        $leadTimePerTipe = $leadRows->groupBy('tipe')
            ->map(fn ($grup, $tipeGrup) => [
                'tipe'      => $tipeGrup,
                'label'     => self::LABEL_TIPE[$tipeGrup] ?? ucfirst((string) $tipeGrup),
                'rata_hari' => round((float) $grup->avg('hari'), 1),
                'jumlah'    => $grup->count(),
            ])->values()->all();

        $ringkasan['menunggu_diproses']   = $menungguRows->count();
        $ringkasan['rata_lead_time_hari'] = $leadRows->isNotEmpty() ? round((float) $leadRows->avg('hari'), 1) : null;

        return [
            'ringkasan'              => $ringkasan,
            'per_bulan'              => $perBulan,
            'per_tipe'               => $perTipe,
            'per_kategori'           => $perKategori,
            'per_departemen'         => $perDepartemen,
            'per_supplier'           => $perSupplier,
            'per_armada'             => $perArmada,
            'sparepart_tanpa_armada' => $sparepartTanpaArmada,
            'aset'                   => $aset,
            'menunggu_lama'          => $menungguLama,
            'lead_time_per_tipe'     => $leadTimePerTipe,
        ];
    }

    private function pastikanAksesLaporan(string $kodePeran): void
    {
        if (!in_array(strtoupper($kodePeran), self::PERAN_LAPORAN, true)) {
            abort(403, 'Laporan pembelian hanya untuk Superadmin, Admin, Manager, Pengadaan, dan Keuangan');
        }
    }

    public function catatUnitDiterima(string $idItem, string $idArmada, string $idPerusahaan, string $idPengguna): void
    {
        $item = $this->findItemMilik($idItem, $idPerusahaan);
        $pesanStatus = 'Unit hanya bisa didaftarkan saat PR berstatus dibeli';
        $header = $this->kunciHeader((string) $item->id_permintaan);
        if (!self::tipeAset($header)) {
            abort(422, $pesanStatus);
        }
        $this->pastikanStatus($header, [self::STATUS_DIBELI], $pesanStatus);
        if (!$this->repo->tambahQtyDiterima($idItem)) {
            abort(422, 'Semua unit item ini sudah terdaftar');
        }
        if (!$this->repo->semuaItemLengkap((string) $header->id_permintaan)) {
            return;
        }

        $pelaku = $idPengguna !== '' ? $idPengguna : null;
        $this->repo->updateHeader($header, [
            'status'                => self::STATUS_DITERIMA,
            'tanggal_diterima'      => now()->toDateString(),
            'keterangan_penerimaan' => 'Semua unit terdaftar di master Armada',
            'diterima_oleh'         => $pelaku,
            'diterima_pada'         => now(),
        ]);
        $hasil = $this->findOrFail((string) $header->id_permintaan, $idPerusahaan);
        $judul = "PR {$hasil->nomor_permintaan} sudah diterima";
        if ($hasil->id_pengaju !== $pelaku) {
            $this->notifikasiKePengaju($hasil, $judul, 'Semua unit terdaftar di master Armada');
        }
        $this->notifikasiKePengadaan($hasil, $judul, 'Semua unit terdaftar di master Armada', $pelaku);

        if ($this->repo->adaTerminMenunggu((string) $header->id_permintaan)) {
            return;
        }
        $this->repo->updateHeader($header, [
            'status'             => self::STATUS_SELESAI,
            'tanggal_pembayaran' => $this->repo->tanggalTransferTerminTerakhir((string) $header->id_permintaan) ?? now()->toDateString(),
        ]);
    }

    public function batalkanUnitDiterima(string $idItem, string $idPerusahaan): void
    {
        $item = $this->findItemMilik($idItem, $idPerusahaan);
        $header = $this->kunciHeader((string) $item->id_permintaan);
        if (in_array($header->status, [self::STATUS_DITERIMA, self::STATUS_SELESAI], true)) {
            abort(422, "Unit ini sudah dikonfirmasi diterima pada PR {$header->nomor_permintaan}, tidak bisa dihapus");
        }
        if ($header->status === self::STATUS_DIBELI) {
            $this->repo->kurangiQtyDiterima($idItem);
        }
    }

    private function findItemMilik(string $idItem, string $idPerusahaan): object
    {
        $item = $this->repo->findItemById($idItem);
        if ($item === null || (string) $item->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Item permintaan tidak ditemukan');
        }
        return $item;
    }

    public function terapkanKeputusanApproval(string $idPermintaan, string $idPerusahaan, string $keputusan, ?string $alasan): void
    {
        $record = $this->repo->findById($idPermintaan);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan || $record->status !== self::STATUS_MENUNGGU_APPROVAL) {
            return;
        }
        $disetujui = $keputusan === 'disetujui';
        $this->repo->updateHeader($record, [
            'status'         => $disetujui ? self::STATUS_DISETUJUI : self::STATUS_DITOLAK,
            'alasan_ditolak' => $disetujui ? null : $alasan,
        ]);
        $segar = $this->repo->findById($idPermintaan);
        if ($disetujui) {
            if ($this->mandiriSparepart($segar, $idPerusahaan)) {
                $this->notifikasiKePengaju($segar, "PR {$segar->nomor_permintaan} disetujui", 'Nilainya di bawah batas mandiri, silakan beli lalu catat realisasi');
                return;
            }
            $this->notifikasiKePengaju($segar, "PR {$segar->nomor_permintaan} disetujui", 'Menunggu diproses Pengadaan');
            $this->notifikasiKePengadaan($segar, "PR {$segar->nomor_permintaan} siap diproses", $segar->judul);
            return;
        }
        $this->notifikasiKePengaju($segar, "PR {$segar->nomor_permintaan} ditolak", $alasan ?? '-');
    }

    private function mandiriSparepart(object $record, string $idPerusahaan): bool
    {
        return self::tipeSparepart($record) && (float) $record->total_estimasi <= $this->arusKasService->batasRealisasiMandiri($idPerusahaan);
    }

    private function susunHeaderItems(array $data, string $idPerusahaan): array
    {
        $idDepartemen = !empty($data['id_departemen']) ? (string) $data['id_departemen'] : null;
        if ($idDepartemen !== null && !$this->repo->departemenMilik($idPerusahaan, $idDepartemen)) {
            abort(422, 'Departemen tidak ditemukan');
        }
        $tipe = (string) ($data['tipe'] ?? self::TIPE_UMUM);
        $adaSparepart = count(array_filter($data['items'], fn ($i) => $i['jenis'] === 'sparepart')) > 0;
        $adaNonSparepart = count(array_filter($data['items'], fn ($i) => $i['jenis'] !== 'sparepart')) > 0;
        if ($tipe === self::TIPE_SPAREPART && $adaNonSparepart) {
            abort(422, 'PR tipe spare part tidak boleh dicampur barang atau jasa');
        }
        if ($tipe !== self::TIPE_SPAREPART && $adaSparepart) {
            abort(422, 'Item spare part hanya untuk PR tipe spare part');
        }
        $adaAset = count(array_filter($data['items'], fn ($i) => $i['jenis'] === 'aset')) > 0;
        $adaNonAset = count(array_filter($data['items'], fn ($i) => $i['jenis'] !== 'aset')) > 0;
        if ($tipe === self::TIPE_ASET && $adaNonAset) {
            abort(422, 'PR tipe aset tidak boleh dicampur jenis item lain');
        }
        if ($tipe !== self::TIPE_ASET && $adaAset) {
            abort(422, 'Item aset hanya untuk PR tipe aset');
        }
        $idPerawatan = null;
        if ($tipe === self::TIPE_SPAREPART && !empty($data['id_perawatan'])) {
            $idPerawatan = (string) $data['id_perawatan'];
            if (!$this->repo->perawatanMilik($idPerusahaan, $idPerawatan)) {
                abort(422, 'Perawatan armada tidak ditemukan');
            }
        }

        $idBarangList =array_values(array_filter(array_map(fn ($i) => $i['id_barang'] ?? null, $data['items'])));
        $barangMap = $this->barangService->pastikanMilik($idBarangList, $idPerusahaan);
        $idSparepartList = array_values(array_filter(array_map(fn ($i) => $i['id_sparepart'] ?? null, $data['items'])));
        $sparepartMap = $this->repo->sparepartMilik($idPerusahaan, $idSparepartList);
        $idJenisList = array_values(array_filter(array_map(fn ($i) => $i['id_jenis_kendaraan'] ?? null, $data['items'])));
        $jenisMap = $this->repo->jenisKendaraanMilik($idPerusahaan, $idJenisList);

        $items = [];
        $total = 0.0;
        foreach ($data['items'] as $item) {
            $harga = (float) $item['harga_estimasi'];
            if ($item['jenis'] === 'aset') {
                $idJenis = !empty($item['id_jenis_kendaraan']) ? (string) $item['id_jenis_kendaraan'] : null;
                if ($idJenis === null) {
                    abort(422, 'Jenis kendaraan unit wajib dipilih');
                }
                if (!isset($jenisMap[$idJenis])) {
                    abort(422, 'Jenis kendaraan tidak ditemukan');
                }
                $merk = trim((string) ($item['merk'] ?? ''));
                if ($merk === '') {
                    abort(422, 'Merk unit wajib diisi');
                }
                $tahun = isset($item['tahun']) && $item['tahun'] !== '' ? (int) $item['tahun'] : null;
                if ($tahun === null || $tahun < 1990 || $tahun > 2100) {
                    abort(422, 'Tahun unit wajib diisi antara 1990 sampai 2100');
                }
                $model = trim((string) ($item['model'] ?? ''));
                $items[] = [
                    'jenis'              => 'aset',
                    'id_barang'          => null,
                    'id_sparepart'       => null,
                    'id_jenis_kendaraan' => $idJenis,
                    'merk'               => $merk,
                    'model'              => $model !== '' ? $model : null,
                    'tahun'              => $tahun,
                    'nama_item'          => trim(preg_replace('/\s+/', ' ', "{$merk} {$model} {$tahun}")),
                    'spesifikasi'        => $item['spesifikasi'] ?? null,
                    'qty'                => (int) $item['qty'],
                    'satuan'             => 'unit',
                    'harga_estimasi'     => $harga,
                    'keterangan'         => $item['keterangan'] ?? null,
                ];
                $total += ((int) $item['qty']) * $harga;
                continue;
            }
            if ($item['jenis'] === 'sparepart') {
                $idSparepart = !empty($item['id_sparepart']) ? (string) $item['id_sparepart'] : null;
                if ($idSparepart === null) {
                    abort(422, 'Item PR spare part wajib dipilih dari master Spare Part');
                }
                $master = $sparepartMap[$idSparepart] ?? null;
                if ($master === null) {
                    abort(422, 'Spare part tidak ditemukan di perusahaan Anda');
                }
                $items[] = [
                    'jenis'          => 'sparepart',
                    'id_barang'      => null,
                    'id_sparepart'   => $idSparepart,
                    'nama_item'      => (string) $master->nama,
                    'spesifikasi'    => $item['spesifikasi'] ?? null,
                    'qty'            => (int) $item['qty'],
                    'satuan'         => (string) $master->satuan,
                    'harga_estimasi' => $harga,
                    'keterangan'     => $item['keterangan'] ?? null,
                ];
                $total += ((int) $item['qty']) * $harga;
                continue;
            }
            $idBarang = ($item['jenis'] === 'barang' && !empty($item['id_barang'])) ? (string) $item['id_barang'] : null;
            $items[] = [
                'jenis'          => $item['jenis'],
                'id_barang'      => $idBarang,
                'id_sparepart'   => null,
                'nama_item'      => $idBarang !== null ? $barangMap[$idBarang]->nama : trim((string) $item['nama_item']),
                'spesifikasi'    => $item['spesifikasi'] ?? null,
                'qty'            => (int) $item['qty'],
                'satuan'         => $idBarang !== null ? $barangMap[$idBarang]->satuan : $item['satuan'],
                'harga_estimasi' => $harga,
                'keterangan'     => $item['keterangan'] ?? null,
            ];
            $total += ((int) $item['qty']) * $harga;
        }

        $header = [
            'judul'              => trim((string) $data['judul']),
            'tipe'               => $tipe,
            'alasan'             => $data['alasan'],
            'id_departemen'      => $idDepartemen,
            'id_perawatan'       => $idPerawatan,
            'tanggal_permintaan' => $data['tanggal_permintaan'],
            'tanggal_dibutuhkan' => $data['tanggal_dibutuhkan'] ?? null,
            'total_estimasi'     => $total,
        ];
        return [$header, $items];
    }

    private function ajukanApproval(object $record, bool $ajukanUlang): string
    {
        $idPerusahaan = (string) $record->id_perusahaan;
        if ($ajukanUlang) {
            $this->approvalService->batalkanUntukReferensi([self::KODE_APPROVAL, self::KODE_APPROVAL_ASET], (string) $record->id_permintaan, $idPerusahaan);
        }
        $kode = $this->kodeApprovalUntuk($record);
        if ($kode === null) {
            return self::STATUS_DISETUJUI;
        }
        $this->approvalService->ajukan($kode, (string) $record->id_permintaan, (string) $record->id_pengaju, (float) $record->total_estimasi, $idPerusahaan);
        return self::STATUS_MENUNGGU_APPROVAL;
    }

    private function kodeApprovalUntuk(object $record): ?string
    {
        $idPerusahaan = (string) $record->id_perusahaan;
        if (self::tipeAset($record) && $this->approvalService->adaEventTypeAktif(self::KODE_APPROVAL_ASET, $idPerusahaan)) {
            return self::KODE_APPROVAL_ASET;
        }
        return $this->approvalService->adaEventTypeAktif(self::KODE_APPROVAL, $idPerusahaan) ? self::KODE_APPROVAL : null;
    }

    /** @param UploadedFile[] $files */
    private function simpanBukti(string $idPermintaan, array $files, string $tahap): void
    {
        foreach ($files as $file) {
            $this->repo->insertBukti([
                'id_permintaan' => $idPermintaan,
                'tahap'         => $tahap,
                'url_file'      => PenyimpananBerkas::simpan($file, 'permintaan-pembelian'),
                'nama_asli'     => $file->getClientOriginalName(),
            ]);
        }
    }

    private function pastikanStatus(object $record, array $boleh, string $pesan): void
    {
        if (!in_array($record->status, $boleh, true)) {
            abort(422, $pesan . " (status saat ini: {$record->status})");
        }
    }

    private function kunciDanPastikanStatus(string $id, array $boleh, string $pesan): object
    {
        $terkunci = $this->kunciHeader($id);
        $this->pastikanStatus($terkunci, $boleh, $pesan);
        return $terkunci;
    }

    private function kunciHeader(string $id): object
    {
        $terkunci = $this->repo->findByIdForUpdate($id);
        if ($terkunci === null) {
            abort(404, 'Permintaan pembelian tidak ditemukan');
        }
        return $terkunci;
    }

    private static function tipeSparepart(object $record): bool
    {
        return ($record->tipe ?? self::TIPE_UMUM) === self::TIPE_SPAREPART;
    }

    private static function tipeAset(object $record): bool
    {
        return ($record->tipe ?? self::TIPE_UMUM) === self::TIPE_ASET;
    }

    private function pastikanBukanSparepart(object $record): void
    {
        if (self::tipeSparepart($record)) {
            abort(422, 'PR spare part ditandai dibeli dan diterima otomatis lewat realisasi Pembelian Sparepart');
        }
    }

    private function pastikanPengadaan(string $kodePeran): void
    {
        if (!in_array(strtoupper($kodePeran), self::PERAN_PENGADAAN, true)) {
            abort(422, 'Aksi ini hanya bisa dilakukan oleh tim Pengadaan');
        }
    }

    private function pastikanPengajuAtauKelola(object $record, string $idPengguna, string $kodePeran): void
    {
        if ($record->id_pengaju === $idPengguna || in_array(strtoupper($kodePeran), self::PERAN_KELOLA, true)) {
            return;
        }
        abort(422, 'Hanya pengaju, Admin, atau tim Pengadaan yang bisa mengubah permintaan ini');
    }

    private function pastikanPengajuAtauPengadaan(object $record, string $idPengguna, string $kodePeran): void
    {
        if ($record->id_pengaju === $idPengguna || in_array(strtoupper($kodePeran), self::PERAN_PENGADAAN, true)) {
            return;
        }
        abort(422, 'Hanya pengaju atau tim Pengadaan yang bisa mengonfirmasi penerimaan');
    }

    private function notifikasiKePengaju(object $record, string $judul, string $isi): void
    {
        $this->notifikasiService->buatDanKirim([
            'id_perusahaan'  => $record->id_perusahaan,
            'id_pengguna'    => $record->id_pengaju,
            'judul'          => $judul,
            'isi'            => $isi,
            'tipe'           => 'permintaan_pembelian',
            'referensi_id'   => $record->id_permintaan,
            'referensi_tipe' => 'permintaan_pembelian',
            'link'           => '/permintaan-pembelian?detail=' . $record->id_permintaan,
            'dibaca'         => 0,
        ]);
    }

    private function notifikasiKePengadaan(object $record, string $judul, string $isi, ?string $kecualiIdPengguna = null): void
    {
        $this->notifikasiService->kirimKePeran(
            self::PERAN_PENGADAAN,
            (string) $record->id_perusahaan,
            $judul,
            $isi,
            'permintaan_pembelian',
            'permintaan_pembelian',
            (string) $record->id_permintaan,
            '/permintaan-pembelian?detail=' . $record->id_permintaan,
            $kecualiIdPengguna,
        );
    }
}
