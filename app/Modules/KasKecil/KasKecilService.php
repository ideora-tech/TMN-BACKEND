<?php

declare(strict_types=1);

namespace App\Modules\KasKecil;

use App\Modules\Approval\ApprovalService;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\KasKecil\Contracts\KasKecilRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KasKecilService
{
    public const JENIS_PENGISIAN   = 'pengisian';
    public const JENIS_SALDO_AWAL  = 'saldo_awal';
    public const JENIS_PENGELUARAN = 'pengeluaran';

    public const LABEL_JENIS = [
        self::JENIS_PENGISIAN   => 'Pengisian dari Rekening',
        self::JENIS_SALDO_AWAL  => 'Saldo Awal',
        self::JENIS_PENGELUARAN => 'Pengeluaran',
    ];

    public const KATEGORI = [
        'atk'          => 'ATK & Perlengkapan Kantor',
        'konsumsi'     => 'Konsumsi & Rapat',
        'transportasi' => 'Transportasi, Parkir & Tol',
        'bbm'          => 'BBM',
        'pengiriman'   => 'Kurir & Pengiriman Dokumen',
        'kebersihan'   => 'Kebersihan & Keamanan',
        'perbaikan'    => 'Perbaikan Kecil',
        'administrasi' => 'Materai & Administrasi',
        'lainnya'      => 'Lainnya',
    ];

    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';
    public const STATUS_MENUNGGU_TRANSFER = 'menunggu_transfer';
    public const STATUS_DITOLAK           = 'ditolak';
    public const STATUS_SELESAI           = 'selesai';

    public const STATUS_PENGAJUAN_BISA_DIUBAH = [
        ArusKasService::STATUS_MENUNGGU_APPROVAL,
        ArusKasService::STATUS_DITOLAK,
    ];

    public const PERAN_SALDO_AWAL = ['SUPERADMIN', 'KEUANGAN'];

    public const PENERIMA_PENGISIAN = 'Kas Kecil';

    private const FOLDER_BUKTI   = 'bukti-kas-kecil';
    private const TOLERANSI      = 0.004;
    private const RENTANG_MAKS   = 366;

    public function __construct(
        private readonly KasKecilRepositoryInterface $repo,
        private readonly ArusKasService $arusKasService,
        private readonly ApprovalService $approvalService,
    ) {}

    public static function statusPengisian(object $pengisian): string
    {
        return match ($pengisian->status_pengajuan ?? null) {
            ArusKasService::STATUS_DITRANSFER    => self::STATUS_SELESAI,
            ArusKasService::STATUS_DITOLAK       => self::STATUS_DITOLAK,
            ArusKasService::STATUS_DISETUJUI,
            ArusKasService::STATUS_DICEK,
            ArusKasService::STATUS_SIAP_TRANSFER => self::STATUS_MENUNGGU_TRANSFER,
            default                              => self::STATUS_MENUNGGU_APPROVAL,
        };
    }

    public static function pengisianBisaDiubah(object $pengisian): bool
    {
        return in_array($pengisian->status_pengajuan ?? null, self::STATUS_PENGAJUAN_BISA_DIUBAH, true);
    }

    public static function labelKategori(?string $kategori): ?string
    {
        if ($kategori === null || $kategori === '') {
            return null;
        }

        return self::KATEGORI[$kategori] ?? ucwords(str_replace('_', ' ', $kategori));
    }

    public function ringkasan(string $idPerusahaan): array
    {
        $bulanIni = $this->repo->totalRentang(
            $idPerusahaan,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );
        $diproses = $this->repo->pengisianDiproses($idPerusahaan);
        $rekening = $this->repo->rekeningPerusahaan($idPerusahaan);

        return [
            'saldo'              => $this->repo->saldo($idPerusahaan),
            'masuk_bulan_ini'    => round((float) $bulanIni->masuk, 2),
            'keluar_bulan_ini'   => round((float) $bulanIni->keluar, 2),
            'pengisian_diproses' => [
                'jumlah'  => (int) $diproses->jumlah,
                'nominal' => round((float) $diproses->nominal, 2),
            ],
            'ada_transaksi'      => $this->repo->adaTransaksi($idPerusahaan),
            'ada_saldo_awal'     => $this->repo->findSaldoAwal($idPerusahaan) !== null,
            'rekening_sumber'    => $this->rekeningTerisi($rekening) ? [
                'nama_bank'          => $rekening->nama_bank,
                'nomor_rekening'     => $rekening->nomor_rekening,
                'atas_nama_rekening' => $rekening->atas_nama_rekening,
            ] : null,
            'opsi_kategori'      => array_map(
                static fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                array_keys(self::KATEGORI),
                array_values(self::KATEGORI),
            ),
        ];
    }

    public function bukuKas(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        [$dari, $sampai] = $this->rentang($dari, $sampai);

        $saldoAwal = $this->repo->saldoSebelum($idPerusahaan, $dari);
        $baris     = $this->repo->transaksiRentang($idPerusahaan, $dari, $sampai);

        $saldo  = $saldoAwal;
        $masuk  = 0.0;
        $keluar = 0.0;
        foreach ($baris as $satu) {
            $nominal = (float) $satu->nominal;
            if ($satu->arah === 'masuk') {
                $masuk += $nominal;
                $saldo += $nominal;
            } else {
                $keluar += $nominal;
                $saldo  -= $nominal;
            }
            $satu->saldo = round($saldo, 2);
        }

        return [
            'periode'      => ['dari' => $dari, 'sampai' => $sampai],
            'saldo_awal'   => round($saldoAwal, 2),
            'total_masuk'  => round($masuk, 2),
            'total_keluar' => round($keluar, 2),
            'saldo_akhir'  => round($saldo, 2),
            'baris'        => $baris,
        ];
    }

    /**
     * @param list<object> $baris
     * @return list<object>
     */
    public function saring(array $baris, ?string $arah, ?string $kategori, ?string $search): array
    {
        $kata = $search !== null ? mb_strtolower(trim($search)) : '';

        return array_values(array_filter($baris, static function (object $satu) use ($arah, $kategori, $kata) {
            if ($arah !== null && $satu->arah !== $arah) {
                return false;
            }
            if ($kategori !== null && ($satu->kategori ?? null) !== $kategori) {
                return false;
            }
            if ($kata === '') {
                return true;
            }

            $teks = mb_strtolower(implode(' ', [
                (string) $satu->nomor_transaksi,
                (string) ($satu->penerima ?? ''),
                (string) $satu->keterangan,
            ]));

            return str_contains($teks, $kata);
        }));
    }

    public function findTransaksiOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findTransaksi($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Transaksi kas kecil tidak ditemukan');
        }

        return $record;
    }

    public function createPengeluaran(array $data, string $idPerusahaan, ?UploadedFile $bukti): object
    {
        $isi = $this->rapikanPengeluaran($data);
        if ($bukti !== null) {
            $isi['url_bukti'] = PenyimpananBerkas::simpan($bukti, self::FOLDER_BUKTI);
        }

        return DB::transaction(function () use ($isi, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);

            $record = $this->repo->createTransaksi(array_merge($isi, [
                'id_perusahaan'   => $idPerusahaan,
                'nomor_transaksi' => $this->repo->nomorTransaksiBerikutnya($idPerusahaan),
                'arah'            => 'keluar',
                'jenis'           => self::JENIS_PENGELUARAN,
            ]));

            $this->pastikanSaldoCukup($idPerusahaan, (string) $isi['tanggal']);

            return $record;
        }, 3);
    }

    public function updatePengeluaran(string $id, array $data, string $idPerusahaan, ?UploadedFile $bukti): object
    {
        $this->findTransaksiOrFail($id, $idPerusahaan);

        $isi = $this->rapikanPengeluaran($data);
        if ($bukti !== null) {
            $isi['url_bukti'] = PenyimpananBerkas::simpan($bukti, self::FOLDER_BUKTI);
        } elseif (!empty($data['hapus_bukti'])) {
            $isi['url_bukti'] = null;
        }

        return DB::transaction(function () use ($id, $isi, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);

            $record = $this->findTransaksiOrFail($id, $idPerusahaan);
            if ($record->jenis !== self::JENIS_PENGELUARAN) {
                abort(409, 'Hanya pengeluaran kas kecil yang bisa diubah');
            }

            $tanggalLama = substr((string) $record->tanggal, 0, 10);
            $updated     = $this->repo->updateTransaksi($record, $isi);

            $this->pastikanSaldoCukup($idPerusahaan, min($tanggalLama, (string) $isi['tanggal']));

            return $updated;
        }, 3);
    }

    public function deletePengeluaran(string $id, string $idPerusahaan): void
    {
        $this->findTransaksiOrFail($id, $idPerusahaan);

        DB::transaction(function () use ($id, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);

            $record = $this->findTransaksiOrFail($id, $idPerusahaan);
            if ($record->jenis === self::JENIS_PENGISIAN) {
                abort(409, 'Penerimaan dari pengisian kas kecil tidak bisa dihapus');
            }
            if ($record->jenis === self::JENIS_SALDO_AWAL) {
                abort(409, 'Saldo awal dihapus lewat tombol Hapus Saldo Awal');
            }

            $this->repo->deleteTransaksi($record);
        }, 3);
    }

    public function catatSaldoAwal(array $data, string $idPerusahaan): object
    {
        $keterangan = trim((string) ($data['keterangan'] ?? ''));

        return DB::transaction(function () use ($data, $keterangan, $idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);

            if ($this->repo->findSaldoAwal($idPerusahaan) !== null) {
                abort(409, 'Saldo awal kas kecil sudah pernah dicatat');
            }
            if ($this->repo->adaTransaksi($idPerusahaan)) {
                abort(409, 'Saldo awal hanya bisa dicatat sebelum ada transaksi kas kecil — untuk menambah dana, ajukan pengisian dari rekening');
            }

            return $this->repo->createTransaksi([
                'id_perusahaan'   => $idPerusahaan,
                'nomor_transaksi' => $this->repo->nomorTransaksiBerikutnya($idPerusahaan),
                'tanggal'         => $data['tanggal'],
                'arah'            => 'masuk',
                'jenis'           => self::JENIS_SALDO_AWAL,
                'nominal'         => round((float) $data['nominal'], 2),
                'keterangan'      => Str::limit($keterangan !== '' ? $keterangan : 'Saldo awal kas kecil', 255, ''),
            ]);
        }, 3);
    }

    public function hapusSaldoAwal(string $idPerusahaan): void
    {
        DB::transaction(function () use ($idPerusahaan) {
            $this->repo->kunciPerusahaan($idPerusahaan);

            $record = $this->repo->findSaldoAwal($idPerusahaan);
            if ($record === null) {
                abort(404, 'Saldo awal kas kecil belum dicatat');
            }

            $this->repo->deleteTransaksi($record);

            $kurang = $this->titikMinus($idPerusahaan, substr((string) $record->tanggal, 0, 10));
            if ($kurang !== null) {
                abort(409, 'Saldo awal tidak bisa dihapus — pengeluaran yang sudah tercatat membuat saldo kas kecil minus per '
                    . $this->tanggalTampil($kurang['tanggal']) . '. Hapus atau kurangi pengeluarannya dulu.');
            }
        }, 3);
    }

    public function listPengisian(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null): array
    {
        $result = $this->repo->paginatePengisian($idPerusahaan, $page, $limit, $search, $status);

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

    public function findPengisianOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findPengisian($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pengisian kas kecil tidak ditemukan');
        }

        return $record;
    }

    public function riwayatPengisian(string $id, string $idPerusahaan): ?array
    {
        $record = $this->findPengisianOrFail($id, $idPerusahaan);
        if ($record->id_pengajuan === null) {
            return null;
        }

        return $this->arusKasService->infoPengajuanById((string) $record->id_pengajuan, $idPerusahaan);
    }

    public function createPengisian(array $data, string $idPerusahaan): object
    {
        $this->pastikanApproverSiap($idPerusahaan, (string) auth()->id());

        return DB::transaction(function () use ($data, $idPerusahaan) {
            $isi      = $this->rapikanPengisian($data);
            $id       = (string) Str::uuid();
            $nomor    = $this->repo->nomorPengisianBerikutnya($idPerusahaan);
            $rekening = $this->repo->rekeningPerusahaan($idPerusahaan);

            $idPengajuan = $this->arusKasService->buatPengajuanPengisianKasKecil(
                $idPerusahaan,
                $id,
                $isi['nominal'],
                self::PENERIMA_PENGISIAN,
                $this->keteranganPengajuan($nomor, $isi['keterangan']),
            )->id_pengajuan;

            return $this->repo->createPengisian(array_merge($isi, [
                'id_pengisian'       => $id,
                'id_perusahaan'      => $idPerusahaan,
                'nomor_pengisian'    => $nomor,
                'nama_bank'          => $rekening->nama_bank ?? null,
                'nomor_rekening'     => $rekening->nomor_rekening ?? null,
                'atas_nama_rekening' => $rekening->atas_nama_rekening ?? null,
                'id_pengajuan'       => $idPengajuan,
            ]));
        }, 3);
    }

    public function updatePengisian(string $id, array $data, string $idPerusahaan): object
    {
        $lama = $this->findPengisianOrFail($id, $idPerusahaan);
        $this->pastikanApproverSiap($idPerusahaan, (string) ($lama->dibuat_oleh ?? auth()->id()));

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $record = $this->kunciPengisianOrFail($id, $idPerusahaan);
            $this->pastikanPengisianBisaDiubah($record, 'diubah');

            $isi = $this->rapikanPengisian($data);

            $this->arusKasService->perbaruiPengajuanPengisianKasKecil(
                (string) $record->id_pengajuan,
                $idPerusahaan,
                $isi['nominal'],
                self::PENERIMA_PENGISIAN,
                $this->keteranganPengajuan((string) $record->nomor_pengisian, $isi['keterangan']),
            );

            return $this->repo->updatePengisian($record, $isi);
        }, 3);
    }

    public function deletePengisian(string $id, string $idPerusahaan): void
    {
        $this->findPengisianOrFail($id, $idPerusahaan);

        DB::transaction(function () use ($id, $idPerusahaan) {
            $record = $this->kunciPengisianOrFail($id, $idPerusahaan);
            $this->pastikanPengisianBisaDiubah($record, 'dihapus');

            $this->arusKasService->hapusPengajuanPengisianKasKecil((string) $record->id_pengajuan, $idPerusahaan);
            $this->repo->deletePengisian($record);
        }, 3);
    }

    private function pastikanApproverSiap(string $idPerusahaan, string $idPembuat): void
    {
        $kode = ArusKasService::KATEGORI_PENGISIAN_KAS_KECIL;

        if ($this->approvalService->eventTypeDinonaktifkan($kode, $idPerusahaan)) {
            return;
        }

        if (!$this->approvalService->approverTersedia($kode, $idPembuat, $idPerusahaan)) {
            abort(422, 'Approver Pengisian Kas Kecil belum diatur — tambahkan Direktur Keuangan sebagai approver di Konfigurasi Approval (jenis Pengisian Kas Kecil, kode "pengisian_kas_kecil")');
        }
    }

    private function kunciPengisianOrFail(string $id, string $idPerusahaan): object
    {
        $this->repo->kunciPengisian($id);

        return $this->findPengisianOrFail($id, $idPerusahaan);
    }

    private function pastikanPengisianBisaDiubah(object $record, string $aksi): void
    {
        if (self::pengisianBisaDiubah($record)) {
            return;
        }

        abort(409, "Pengisian kas kecil hanya bisa {$aksi} saat status menunggu approval atau ditolak (status saat ini: "
            . str_replace('_', ' ', self::statusPengisian($record)) . ')');
    }

    private function pastikanSaldoCukup(string $idPerusahaan, string $dariTanggal): void
    {
        $kurang = $this->titikMinus($idPerusahaan, $dariTanggal);
        if ($kurang === null) {
            return;
        }

        throw ValidationException::withMessages([
            'nominal' => 'Saldo kas kecil tidak cukup per ' . $this->tanggalTampil($kurang['tanggal'])
                . ' — kurang Rp ' . number_format($kurang['kurang'], 0, ',', '.')
                . '. Ajukan pengisian kas kecil atau periksa tanggal dan nominalnya.',
        ]);
    }

    /** @return array{tanggal: string, kurang: float}|null */
    private function titikMinus(string $idPerusahaan, string $dariTanggal): ?array
    {
        $saldo = $this->repo->saldoSebelum($idPerusahaan, $dariTanggal, true);

        foreach ($this->repo->mutasiSejak($idPerusahaan, $dariTanggal) as $mutasi) {
            $nominal = (float) $mutasi->nominal;
            $saldo   = round($saldo + ($mutasi->arah === 'masuk' ? $nominal : -$nominal), 2);

            if ($saldo < -self::TOLERANSI) {
                return ['tanggal' => substr((string) $mutasi->tanggal, 0, 10), 'kurang' => abs($saldo)];
            }
        }

        return null;
    }

    private function rapikanPengeluaran(array $data): array
    {
        $penerima = trim((string) ($data['penerima'] ?? ''));

        return [
            'tanggal'    => $data['tanggal'],
            'kategori'   => $data['kategori'],
            'nominal'    => round((float) $data['nominal'], 2),
            'penerima'   => $penerima !== '' ? $penerima : null,
            'keterangan' => trim((string) $data['keterangan']),
        ];
    }

    private function rapikanPengisian(array $data): array
    {
        return [
            'tanggal'    => $data['tanggal'],
            'nominal'    => round((float) $data['nominal'], 2),
            'keterangan' => trim((string) $data['keterangan']),
        ];
    }

    private function keteranganPengajuan(string $nomor, string $keterangan): string
    {
        return Str::limit("Pengisian kas kecil {$nomor} — {$keterangan}", 255, '');
    }

    private function rekeningTerisi(?object $rekening): bool
    {
        return $rekening !== null
            && trim((string) ($rekening->nama_bank ?? '')) !== ''
            && trim((string) ($rekening->nomor_rekening ?? '')) !== '';
    }

    /** @return array{0: string, 1: string} */
    private function rentang(?string $dari, ?string $sampai): array
    {
        $dari   = $dari ?: now()->startOfMonth()->toDateString();
        $sampai = $sampai ?: now()->endOfMonth()->toDateString();

        $mulai = Carbon::parse($dari);
        $akhir = Carbon::parse($sampai);
        if ($mulai->gt($akhir)) {
            abort(422, 'Tanggal dari tidak boleh melebihi tanggal sampai');
        }
        if ($mulai->diffInDays($akhir) > self::RENTANG_MAKS) {
            abort(422, 'Rentang tanggal maksimal ' . self::RENTANG_MAKS . ' hari');
        }

        return [$mulai->toDateString(), $akhir->toDateString()];
    }

    private function tanggalTampil(string $tanggal): string
    {
        return Carbon::parse($tanggal)->format('d/m/Y');
    }
}
