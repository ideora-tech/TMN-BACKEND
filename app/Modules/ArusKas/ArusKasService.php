<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use App\Modules\Trip\TripModel;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArusKasService
{
    public const STATUS_DIAJUKAN   = 'diajukan';
    public const STATUS_DICEK      = 'dicek';
    public const STATUS_DISETUJUI  = 'disetujui';
    public const STATUS_DITOLAK    = 'ditolak';
    public const STATUS_DITRANSFER = 'ditransfer';

    public function __construct(private readonly ArusKasRepositoryInterface $repo) {}

    public function infoPengajuanTrip(string $idTrip): ?array
    {
        $record = $this->repo->findPengajuanByTrip($idTrip);
        if ($record === null) {
            return null;
        }

        $namaMap = $this->repo->namaPengguna(array_values(array_filter([
            $record->dibuat_oleh,
            $record->dicek_oleh,
            $record->disetujui_oleh,
            $record->ditransfer_oleh,
        ])));

        $riwayat = [[
            'status'     => self::STATUS_DIAJUKAN,
            'waktu'      => $record->dibuat_pada,
            'oleh'       => $record->dibuat_oleh !== null ? ($namaMap[$record->dibuat_oleh] ?? null) : null,
            'keterangan' => $record->keterangan,
        ]];

        if ($record->dicek_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DICEK,
                'waktu'      => $record->dicek_pada,
                'oleh'       => $record->dicek_oleh !== null ? ($namaMap[$record->dicek_oleh] ?? null) : null,
                'keterangan' => null,
            ];
        }

        if ($record->disetujui_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DISETUJUI,
                'waktu'      => $record->disetujui_pada,
                'oleh'       => $record->disetujui_oleh !== null ? ($namaMap[$record->disetujui_oleh] ?? null) : null,
                'keterangan' => null,
            ];
        }

        if ($record->status === self::STATUS_DITOLAK) {
            $riwayat[] = [
                'status'     => self::STATUS_DITOLAK,
                'waktu'      => $record->diubah_pada,
                'oleh'       => null,
                'keterangan' => $record->alasan_ditolak,
            ];
        }

        if ($record->ditransfer_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DITRANSFER,
                'waktu'      => $record->ditransfer_pada,
                'oleh'       => $record->ditransfer_oleh !== null ? ($namaMap[$record->ditransfer_oleh] ?? null) : null,
                'keterangan' => $record->tanggal_transfer !== null ? 'Tanggal transfer ' . $record->tanggal_transfer : null,
            ];
        }

        return [
            'id_pengajuan'    => $record->id_pengajuan,
            'nomor_pengajuan' => $record->nomor_pengajuan,
            'status'          => $record->status,
            'nominal'         => (float) $record->nominal,
            'riwayat'         => $riwayat,
        ];
    }

    public function listPengajuan(string $idPerusahaan, ?string $status = null): array
    {
        return $this->repo->listPengajuanByPerusahaan($idPerusahaan, $status)->all();
    }

    public function findPengajuanOrFail(string $id, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->repo->findPengajuanById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pengajuan pengeluaran tidak ditemukan');
        }
        return $record;
    }

    public function createPengajuan(array $data, string $idPerusahaan, ?UploadedFile $bukti): PengajuanPengeluaranModel
    {
        return DB::transaction(function () use ($data, $idPerusahaan, $bukti) {
            $data['id_perusahaan']   = $idPerusahaan;
            $data['status']          = self::STATUS_DIAJUKAN;
            $data['nomor_pengajuan'] = $this->repo->nomorPengajuanBerikutnya($idPerusahaan);
            if ($bukti !== null) {
                $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
            }
            return $this->repo->createPengajuan($data);
        });
    }

    public function updatePengajuan(string $id, array $data, string $idPerusahaan, ?UploadedFile $bukti): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN], 'Pengajuan hanya bisa diubah saat status diajukan');
        if ($bukti !== null) {
            $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
        }
        return $this->repo->updatePengajuan($record, $data);
    }

    public function deletePengajuan(string $id, string $idPerusahaan): void
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN], 'Pengajuan hanya bisa dihapus saat status diajukan');
        $this->repo->deletePengajuan($record);
    }

    public function cek(string $id, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN], 'Pengajuan tidak bisa dicek dari status saat ini');
        return $this->repo->updatePengajuan($record, [
            'status'     => self::STATUS_DICEK,
            'dicek_oleh' => auth()->id(),
            'dicek_pada' => now(),
        ]);
    }

    public function setujui(string $id, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DICEK], 'Pengajuan tidak bisa disetujui dari status saat ini');

        return DB::transaction(function () use ($record) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DISETUJUI,
                'disetujui_oleh' => auth()->id(),
                'disetujui_pada' => now(),
            ]);
            if ($record->id_pembelian !== null) {
                $this->repo->sinkronPembelianSetujui($record->id_pembelian);
            }
            return $updated;
        });
    }

    public function tolak(string $id, string $alasan, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DIAJUKAN, self::STATUS_DICEK], 'Pengajuan tidak bisa ditolak dari status saat ini');

        return DB::transaction(function () use ($record, $alasan) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DITOLAK,
                'alasan_ditolak' => $alasan,
            ]);
            if ($record->id_pembelian !== null) {
                $this->repo->sinkronPembelianTolak($record->id_pembelian, $alasan);
            }
            return $updated;
        });
    }

    public function transfer(string $id, string $tanggalTransfer, ?UploadedFile $bukti, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI], 'Pengajuan hanya bisa ditransfer setelah disetujui');

        $statusPembelian = null;
        if ($record->id_pembelian !== null) {
            $statusPembelian = $this->repo->statusPembelian($record->id_pembelian);
            if (!in_array($statusPembelian, ['disetujui_finance', 'dibeli'], true)) {
                abort(409, 'Pembelian sparepart belum disetujui finance (status saat ini: ' . ($statusPembelian ?? 'tidak ditemukan') . '), transfer tidak bisa dilakukan');
            }
        }

        return DB::transaction(function () use ($record, $tanggalTransfer, $bukti, $statusPembelian) {
            $data = [
                'status'           => self::STATUS_DITRANSFER,
                'tanggal_transfer' => $tanggalTransfer,
                'ditransfer_oleh'  => auth()->id(),
                'ditransfer_pada'  => now(),
            ];
            if ($bukti !== null) {
                $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
            }
            $updated = $this->repo->updatePengajuan($record, $data);
            if ($record->id_pembelian !== null) {
                if ($statusPembelian === 'dibeli') {
                    $this->repo->sinkronPembelianLunas($record->id_pembelian, $tanggalTransfer);
                } else {
                    $this->repo->sinkronPembelianUangMuka($record->id_pembelian, $tanggalTransfer);
                }
            }
            return $updated;
        });
    }

    public function findPemasukanOrFail(string $id, string $idPerusahaan): PemasukanModel
    {
        $record = $this->repo->findPemasukanById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pemasukan tidak ditemukan');
        }
        return $record;
    }

    public function createPemasukan(array $data, string $idPerusahaan, ?UploadedFile $bukti): PemasukanModel
    {
        return DB::transaction(function () use ($data, $idPerusahaan, $bukti) {
            $data['id_perusahaan']   = $idPerusahaan;
            $data['nomor_pemasukan'] = $this->repo->nomorPemasukanBerikutnya($idPerusahaan);
            if ($bukti !== null) {
                $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
            }
            return $this->repo->createPemasukan($data);
        });
    }

    public function updatePemasukan(string $id, array $data, string $idPerusahaan, ?UploadedFile $bukti): PemasukanModel
    {
        $record = $this->findPemasukanOrFail($id, $idPerusahaan);
        if ($bukti !== null) {
            $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
        }
        return $this->repo->updatePemasukan($record, $data);
    }

    public function deletePemasukan(string $id, string $idPerusahaan): void
    {
        $this->repo->deletePemasukan($this->findPemasukanOrFail($id, $idPerusahaan));
    }

    public function listPemasukan(string $idPerusahaan, ?string $dari, ?string $sampai, ?string $jenis, ?string $kategori): array
    {
        $dari   = $dari ?: now()->startOfMonth()->toDateString();
        $sampai = $sampai ?: now()->endOfMonth()->toDateString();

        $this->validasiRentang($dari, $sampai);

        return $this->repo->listPemasukanGabungan($idPerusahaan, $dari, $sampai)
            ->when($jenis, fn (Collection $c, string $v) => $c->where('jenis', $v))
            ->when($kategori, fn (Collection $c, string $v) => $c->where('kategori', $v))
            ->values()
            ->all();
    }

    public function buatPengajuanUangJalanOtomatis(TripModel $trip): void
    {
        if ($trip->uang_jalan_alokasi === null || (float) $trip->uang_jalan_alokasi <= 0) {
            return;
        }
        if ($this->repo->findPengajuanByTrip($trip->id_trip) !== null) {
            return;
        }

        $data = $this->repo->dataTripUntukPengajuan($trip->id_trip);
        if ($data === null) {
            return;
        }

        $idPerusahaan = (string) $data->id_perusahaan;

        $this->repo->createPengajuan([
            'id_perusahaan'     => $idPerusahaan,
            'id_trip'           => $trip->id_trip,
            'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
            'kategori'          => 'uang_jalan',
            'nominal'           => (float) $trip->uang_jalan_alokasi,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => $data->penerima !== null && $data->penerima !== '' ? $data->penerima : '-',
            'keterangan'        => $data->keterangan,
            'status'            => self::STATUS_DIAJUKAN,
        ]);
    }

    public function sinkronNominalPengajuanTrip(string $idTrip, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $record = $this->repo->findPengajuanByTrip($idTrip);
        if ($record === null || $record->status !== self::STATUS_DIAJUKAN) {
            return;
        }

        $this->repo->updatePengajuan($record, ['nominal' => $nominal]);
    }

    public function buatPengajuanPerawatanOtomatis(object $perawatan, float $totalBiaya): void
    {
        if ($totalBiaya <= 0) {
            return;
        }
        if ($this->repo->findPengajuanByPerawatan($perawatan->id_perawatan) !== null) {
            return;
        }

        $data = $this->repo->dataPerawatanUntukPengajuan($perawatan->id_perawatan);
        if ($data === null) {
            return;
        }

        $idPerusahaan = (string) $data->id_perusahaan;
        $nopol = $data->nopol !== null && $data->nopol !== '' ? $data->nopol : null;

        $this->repo->createPengajuan([
            'id_perusahaan'     => $idPerusahaan,
            'id_perawatan'      => $perawatan->id_perawatan,
            'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
            'kategori'          => 'perawatan',
            'nominal'           => $totalBiaya,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => $nopol ?? '-',
            'keterangan'        => trim(($data->jenis_perawatan ?? '') . ($nopol !== null ? " - {$nopol}" : '')),
            'status'            => self::STATUS_DIAJUKAN,
        ]);
    }

    public function sinkronNominalPengajuanPerawatan(string $idPerawatan, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $record = $this->repo->findPengajuanByPerawatan($idPerawatan);
        if ($record === null || $record->status !== self::STATUS_DIAJUKAN) {
            return;
        }

        $this->repo->updatePengajuan($record, ['nominal' => $nominal]);
    }

    public function hapusPengajuanPerawatan(string $idPerawatan): void
    {
        $record = $this->repo->findPengajuanByPerawatan($idPerawatan);
        if ($record === null || $record->status === self::STATUS_DITRANSFER) {
            return;
        }

        $this->repo->deletePengajuan($record);
    }

    public function buatPengajuanPembelianOtomatis(object $pembelian, float $totalEstimasi): void
    {
        if ($totalEstimasi <= 0) {
            return;
        }
        if ($this->repo->findPengajuanByPembelian($pembelian->id_pembelian) !== null) {
            return;
        }

        $data = $this->repo->dataPembelianUntukPengajuan($pembelian->id_pembelian);
        if ($data === null || $data->id_perawatan !== null) {
            return;
        }

        $idPerusahaan = (string) $data->id_perusahaan;
        $namaSupplier = $data->nama_supplier !== null && $data->nama_supplier !== '' ? $data->nama_supplier : '-';

        $this->repo->createPengajuan([
            'id_perusahaan'     => $idPerusahaan,
            'id_pembelian'      => $pembelian->id_pembelian,
            'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
            'kategori'          => 'sparepart',
            'nominal'           => $totalEstimasi,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => $namaSupplier,
            'keterangan'        => trim(($data->nomor_ps ?? '') . ($data->nama_supplier !== null && $data->nama_supplier !== '' ? " - {$data->nama_supplier}" : '')),
            'status'            => self::STATUS_DIAJUKAN,
        ]);
    }

    public function sinkronNominalPengajuanPembelian(string $idPembelian, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $record = $this->repo->findPengajuanByPembelian($idPembelian);
        if ($record === null || !in_array($record->status, [self::STATUS_DIAJUKAN, self::STATUS_DISETUJUI], true)) {
            return;
        }

        $this->repo->updatePengajuan($record, ['nominal' => $nominal]);
    }

    public function hapusPengajuanPembelian(string $idPembelian): void
    {
        $record = $this->repo->findPengajuanByPembelian($idPembelian);
        if ($record === null || $record->status === self::STATUS_DITRANSFER) {
            return;
        }

        $this->repo->deletePengajuan($record);
    }

    public function buatPengajuanPayrollOtomatis(object $periode): void
    {
        if ($this->repo->findPengajuanByPeriode($periode->id_periode) !== null) {
            return;
        }

        $data = $this->repo->dataPeriodeUntukPengajuan($periode->id_periode);
        if ($data === null || (float) $data->total_gaji_bersih <= 0) {
            return;
        }

        $idPerusahaan = (string) $data->id_perusahaan;
        $rentang = Carbon::parse($data->tanggal_mulai)->format('d/m/Y') . ' - ' . Carbon::parse($data->tanggal_selesai)->format('d/m/Y');

        $this->repo->createPengajuan([
            'id_perusahaan'     => $idPerusahaan,
            'id_periode'        => $periode->id_periode,
            'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
            'kategori'          => 'penggajian',
            'nominal'           => (float) $data->total_gaji_bersih,
            'tanggal_pengajuan' => now()->toDateString(),
            'penerima'          => 'Seluruh karyawan',
            'keterangan'        => "{$data->nama} ({$rentang})",
            'status'            => self::STATUS_DIAJUKAN,
        ]);
    }

    public function batalkanPengajuanPayroll(string $idPeriode): void
    {
        $record = $this->repo->findPengajuanByPeriode($idPeriode);
        if ($record === null) {
            return;
        }
        if ($record->status === self::STATUS_DITRANSFER) {
            abort(409, 'Gaji periode ini sudah ditransfer Keuangan — batalkan tidak diizinkan');
        }
        $this->repo->deletePengajuan($record);
    }

    private function pastikanStatus(PengajuanPengeluaranModel $record, array $boleh, string $pesan): void
    {
        if (!in_array($record->status, $boleh, true)) {
            abort(409, $pesan . " (status saat ini: {$record->status})");
        }
    }

    public function rekap(string $idPerusahaan, ?string $dari, ?string $sampai, ?string $arah, ?string $sumber): array
    {
        $dari   = $dari ?: now()->startOfMonth()->toDateString();
        $sampai = $sampai ?: now()->endOfMonth()->toDateString();

        $this->validasiRentang($dari, $sampai);

        $semua = $this->repo->rekap($idPerusahaan, $dari, $sampai);

        $ringkasan = $this->hitungRingkasan($semua);

        $transaksi = $semua
            ->when($arah, fn (Collection $c, string $v) => $c->where('arah', $v))
            ->when($sumber, fn (Collection $c, string $v) => $c->where('sumber', $v))
            ->values();

        return [
            'ringkasan' => $ringkasan,
            'transaksi' => $transaksi,
        ];
    }

    private function validasiRentang(string $dari, string $sampai): void
    {
        $mulai = Carbon::parse($dari);
        $akhir = Carbon::parse($sampai);

        if ($mulai->gt($akhir)) {
            abort(422, 'Tanggal dari tidak boleh melebihi tanggal sampai');
        }
        if ($mulai->diffInDays($akhir) > 366) {
            abort(422, 'Rentang tanggal maksimal 366 hari');
        }
    }

    private function hitungRingkasan(Collection $rows): array
    {
        $pemasukan   = (float) $rows->where('arah', 'masuk')->sum(fn ($r) => (float) $r->nominal);
        $pengeluaran = (float) $rows->where('arah', 'keluar')->sum(fn ($r) => (float) $r->nominal);

        return [
            'total_pemasukan'   => $pemasukan,
            'total_pengeluaran' => $pengeluaran,
            'netto'             => $pemasukan - $pengeluaran,
        ];
    }
}
