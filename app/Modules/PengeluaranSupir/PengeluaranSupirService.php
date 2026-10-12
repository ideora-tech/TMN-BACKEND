<?php

declare(strict_types=1);

namespace App\Modules\PengeluaranSupir;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\PengeluaranSupir\Contracts\PengeluaranSupirRepositoryInterface;
use App\Modules\Supir\Contracts\SupirRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PengeluaranSupirService
{
    public const STATUS_MENUNGGU_PERSETUJUAN = 'menunggu_persetujuan';
    public const STATUS_MENUNGGU_PEMBAYARAN  = 'menunggu_pembayaran';
    public const STATUS_DIBAYAR              = 'dibayar';
    public const STATUS_DITOLAK              = 'ditolak';

    public const LABEL_STATUS = [
        self::STATUS_MENUNGGU_PERSETUJUAN => 'Menunggu Persetujuan',
        self::STATUS_MENUNGGU_PEMBAYARAN  => 'Menunggu Pembayaran',
        self::STATUS_DIBAYAR              => 'Sudah Dibayar',
        self::STATUS_DITOLAK              => 'Ditolak',
    ];

    public const HARI_MUNDUR      = 30;
    public const NOMINAL_MAKSIMAL = 10_000_000;
    public const MAKS_FOTO        = 3;
    public const MAKS_PER_HARI    = 20;

    private const STATUS_PENGAJUAN = [
        self::STATUS_MENUNGGU_PERSETUJUAN => [ArusKasService::STATUS_DIAJUKAN, ArusKasService::STATUS_MENUNGGU_APPROVAL],
        self::STATUS_MENUNGGU_PEMBAYARAN  => [ArusKasService::STATUS_DISETUJUI, ArusKasService::STATUS_DICEK, ArusKasService::STATUS_SIAP_TRANSFER],
        self::STATUS_DIBAYAR              => [ArusKasService::STATUS_DITRANSFER],
        self::STATUS_DITOLAK              => [ArusKasService::STATUS_DITOLAK],
    ];

    private const STATUS_BISA_DIBATALKAN = [
        ArusKasService::STATUS_MENUNGGU_APPROVAL,
        ArusKasService::STATUS_DITOLAK,
    ];

    private const MENIT_CEGAH_KEMBAR = 10;

    public function __construct(
        private readonly PengeluaranSupirRepositoryInterface $repo,
        private readonly SupirRepositoryInterface $supirRepo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public static function statusDari(object $record): string
    {
        $statusPengajuan = $record->status_pengajuan ?? null;
        foreach (self::STATUS_PENGAJUAN as $status => $daftar) {
            if (in_array($statusPengajuan, $daftar, true)) {
                return $status;
            }
        }

        return self::STATUS_MENUNGGU_PERSETUJUAN;
    }

    public static function bisaDibatalkan(object $record): bool
    {
        return in_array($record->status_pengajuan ?? null, self::STATUS_BISA_DIBATALKAN, true);
    }

    public function supirUntukPengguna(string $idPengguna, bool $akunAktif = true): object
    {
        if (!$akunAktif) {
            abort(403, 'Akun Anda sudah tidak aktif');
        }

        $supir = $this->supirRepo->findByPengguna($idPengguna);
        if ($supir === null) {
            abort(403, 'Pengajuan pengeluaran hanya untuk supir internal');
        }

        return $supir;
    }

    public function opsi(object $supir): array
    {
        $jenis = [];
        foreach (ArusKasService::KATEGORI_PENGELUARAN_SUPIR as $value => $label) {
            $jenis[] = ['value' => $value, 'label' => $label];
        }

        return [
            'jenis'               => $jenis,
            'unit'                => $this->unit($supir),
            'rekening'            => $this->rekeningAwal($supir),
            'tanggal_paling_awal' => $this->tanggalPalingAwal(),
            'nominal_maksimal'    => self::NOMINAL_MAKSIMAL,
            'maks_foto'           => self::MAKS_FOTO,
        ];
    }

    public function list(object $supir, int $page, int $limit, ?string $status): array
    {
        if ($status !== null && !isset(self::STATUS_PENGAJUAN[$status])) {
            abort(422, 'Status tidak dikenal');
        }

        $result = $this->repo->paginateBySupir(
            (string) $supir->id_supir,
            $page,
            $limit,
            $status !== null ? self::STATUS_PENGAJUAN[$status] : null,
        );

        return [
            'data' => $this->lampirkanFoto($result->items()),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
            ],
        ];
    }

    public function detailMilik(string $id, object $supir): object
    {
        return $this->lampirkanFoto([$this->findMilikOrFail($id, $supir)])[0];
    }

    public function detail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Pengeluaran supir tidak ditemukan');
        }

        return $this->lampirkanFoto([$record])[0];
    }

    /** @param list<UploadedFile> $foto */
    public function create(array $data, array $foto, object $supir): object
    {
        if ((string) $supir->status !== 'aktif') {
            abort(403, 'Akun supir Anda sudah tidak aktif');
        }

        $idSupir = (string) $supir->id_supir;
        $jenis   = (string) $data['jenis'];
        if (!isset(ArusKasService::KATEGORI_PENGELUARAN_SUPIR[$jenis])) {
            $this->tolak('jenis', 'Jenis pengeluaran tidak dikenal');
        }

        $unit     = $this->unitTerpilih($supir, $data);
        $nominal  = round((float) $data['nominal'], 2);
        $tanggal  = (string) $data['tanggal'];
        $catatan  = trim((string) ($data['keterangan'] ?? ''));
        $bank     = trim((string) ($data['nama_bank'] ?? ''));
        $rekening = trim((string) ($data['nomor_rekening'] ?? ''));
        $atasNama = trim((string) ($data['atas_nama_rekening'] ?? ''));
        $sejak    = now()->subMinutes(self::MENIT_CEGAH_KEMBAR)->toDateTimeString();

        $this->pastikanBukanKembaran($idSupir, $jenis, $unit, $tanggal, $nominal, $sejak);
        $this->pastikanDiBawahBatasHarian($idSupir);

        $urlFoto = array_map(
            static fn (UploadedFile $berkas) => PenyimpananBerkas::simpan($berkas, 'pengeluaran-supir'),
            array_values($foto),
        );

        try {
            return $this->simpan($supir, $jenis, $unit, $nominal, $tanggal, $catatan, $bank, $rekening, $atasNama, $sejak, $urlFoto);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($urlFoto);

            throw $e;
        }
    }

    /**
     * @param array{id_armada: ?string, id_armada_vendor: ?string, nopol: string} $unit
     * @param list<string> $urlFoto
     */
    private function simpan(
        object $supir,
        string $jenis,
        array $unit,
        float $nominal,
        string $tanggal,
        string $catatan,
        string $bank,
        string $rekening,
        string $atasNama,
        string $sejak,
        array $urlFoto,
    ): object {
        $idSupir      = (string) $supir->id_supir;
        $idPerusahaan = (string) $supir->id_perusahaan;

        return DB::transaction(function () use ($supir, $idSupir, $idPerusahaan, $jenis, $unit, $nominal, $tanggal, $catatan, $bank, $rekening, $atasNama, $sejak, $urlFoto) {
            $this->repo->kunciSupir($idSupir);
            $this->pastikanBukanKembaran($idSupir, $jenis, $unit, $tanggal, $nominal, $sejak);
            $this->pastikanDiBawahBatasHarian($idSupir);

            $id    = (string) Str::uuid();
            $nomor = $this->repo->nomorBerikutnya($idPerusahaan);

            try {
                $pengajuan = $this->arusKasService->buatPengajuanPengeluaranSupir(
                    $idPerusahaan,
                    $id,
                    $jenis,
                    $nominal,
                    Str::limit((string) $supir->nama, 150, ''),
                    $this->keteranganPengajuan($nomor, $jenis, $unit, $catatan),
                );
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 422) {
                    throw $e;
                }
                abort(422, 'Pengajuan belum bisa dikirim karena alur persetujuan di kantor belum lengkap. Hubungi admin kantor. (' . $e->getMessage() . ')');
            }

            $record = $this->repo->create([
                'id_pengeluaran_supir' => $id,
                'id_perusahaan'        => $idPerusahaan,
                'nomor'                => $nomor,
                'id_supir'             => $idSupir,
                'jenis'                => $jenis,
                'tanggal'              => $tanggal,
                'nominal'              => $nominal,
                'id_armada'            => $unit['id_armada'],
                'id_armada_vendor'     => $unit['id_armada_vendor'],
                'nopol'                => $unit['nopol'],
                'keterangan'           => $catatan !== '' ? $catatan : null,
                'nama_bank'            => $bank !== '' ? $bank : null,
                'nomor_rekening'       => $rekening !== '' ? $rekening : null,
                'atas_nama_rekening'   => $atasNama !== '' ? $atasNama : null,
                'id_pengajuan'         => $pengajuan->id_pengajuan,
            ], $urlFoto);

            return $this->lampirkanFoto([$record])[0];
        }, 3);
    }

    public function batalkan(string $id, object $supir): void
    {
        $this->findMilikOrFail($id, $supir);

        DB::transaction(function () use ($id, $supir) {
            $this->repo->kunci($id);
            $record = $this->findMilikOrFail($id, $supir);
            if (!self::bisaDibatalkan($record)) {
                abort(409, 'Pengajuan yang sudah disetujui atau dibayar tidak bisa dibatalkan');
            }

            if ($record->id_pengajuan !== null) {
                $this->arusKasService->hapusPengajuanPengeluaranSupir((string) $record->id_pengajuan, (string) $record->id_perusahaan);
            }

            $this->repo->delete($record);
        }, 3);
    }

    private function findMilikOrFail(string $id, object $supir): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_supir !== $supir->id_supir) {
            abort(404, 'Pengajuan tidak ditemukan');
        }

        return $record;
    }

    /**
     * @param list<object> $records
     * @return list<object>
     */
    private function lampirkanFoto(array $records): array
    {
        $foto = $this->repo->fotoUntuk(array_map(static fn (object $r) => (string) $r->id_pengeluaran_supir, $records));
        foreach ($records as $record) {
            $record->foto = $foto[(string) $record->id_pengeluaran_supir] ?? [];
        }

        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function unit(object $supir): array
    {
        $idSupir      = (string) $supir->id_supir;
        $idPerusahaan = (string) $supir->id_perusahaan;
        $dari         = $this->tanggalPalingAwal();
        $sampai       = now()->toDateString();
        $default      = $supir->id_armada_default !== null ? (string) $supir->id_armada_default : null;

        $hasil = [];
        foreach ($this->repo->unitInternal($idSupir, $idPerusahaan, $default, $dari, $sampai) as $armada) {
            $merk    = trim(($armada->merk ?? '') . ' ' . ($armada->model ?? ''));
            $hasil[] = [
                'kunci'            => 'armada:' . $armada->id_armada,
                'id_armada'        => $armada->id_armada,
                'id_armada_vendor' => null,
                'nopol'            => $armada->nopol,
                'keterangan'       => $merk !== '' ? $merk : null,
                'unit_vendor'      => false,
            ];
        }
        foreach ($this->repo->unitVendor($idSupir, $idPerusahaan, $dari, $sampai) as $armada) {
            $hasil[] = [
                'kunci'            => 'vendor:' . $armada->id_armada_vendor,
                'id_armada'        => null,
                'id_armada_vendor' => $armada->id_armada_vendor,
                'nopol'            => $armada->nopol,
                'keterangan'       => 'Unit vendor ' . $armada->nama_vendor,
                'unit_vendor'      => true,
            ];
        }

        return $hasil;
    }

    /** @return array{id_armada: ?string, id_armada_vendor: ?string, nopol: string} */
    private function unitTerpilih(object $supir, array $data): array
    {
        $idArmada       = trim((string) ($data['id_armada'] ?? ''));
        $idArmadaVendor = trim((string) ($data['id_armada_vendor'] ?? ''));
        $kunci          = $idArmada !== '' ? 'armada:' . $idArmada : 'vendor:' . $idArmadaVendor;

        foreach ($this->unit($supir) as $unit) {
            if ($unit['kunci'] === $kunci) {
                return [
                    'id_armada'        => $unit['id_armada'],
                    'id_armada_vendor' => $unit['id_armada_vendor'],
                    'nopol'            => (string) $unit['nopol'],
                ];
            }
        }

        $this->tolak(
            $idArmada !== '' ? 'id_armada' : 'id_armada_vendor',
            'Unit ini tidak ditugaskan kepada Anda dalam ' . self::HARI_MUNDUR . ' hari terakhir',
        );
    }

    /** @return array{nama_bank: ?string, nomor_rekening: ?string, atas_nama_rekening: ?string} */
    private function rekeningAwal(object $supir): array
    {
        $sumber = $this->repo->rekeningTerakhir((string) $supir->id_supir);
        if ($sumber === null && $supir->id_karyawan !== null) {
            $sumber = $this->repo->rekeningKaryawan((string) $supir->id_karyawan, (string) $supir->id_perusahaan);
        }

        return [
            'nama_bank'          => $this->teksAtauNull($sumber->nama_bank ?? null),
            'nomor_rekening'     => $this->teksAtauNull($sumber->nomor_rekening ?? null),
            'atas_nama_rekening' => $this->teksAtauNull($sumber->atas_nama_rekening ?? null),
        ];
    }

    /** @param array{id_armada: ?string, id_armada_vendor: ?string, nopol: string} $unit */
    private function pastikanBukanKembaran(string $idSupir, string $jenis, array $unit, string $tanggal, float $nominal, string $sejak): void
    {
        if ($this->repo->adaKembaran($idSupir, $jenis, $unit['id_armada'], $unit['id_armada_vendor'], $tanggal, $nominal, $sejak)) {
            abort(409, 'Pengajuan yang sama baru saja dikirim — periksa daftar pengajuan Anda');
        }
    }

    private function pastikanDiBawahBatasHarian(string $idSupir): void
    {
        if ($this->repo->jumlahSejak($idSupir, now()->startOfDay()->toDateTimeString()) >= self::MAKS_PER_HARI) {
            abort(429, 'Batas pengajuan hari ini sudah tercapai (' . self::MAKS_PER_HARI . ' pengajuan). Coba lagi besok atau hubungi admin kantor.');
        }
    }

    /** @param array{id_armada: ?string, id_armada_vendor: ?string, nopol: string} $unit */
    private function keteranganPengajuan(string $nomor, string $jenis, array $unit, string $catatan): string
    {
        $bagian = [
            ArusKasService::labelKategori($jenis) . ' ' . $nomor,
            $unit['nopol'] . ($unit['id_armada_vendor'] !== null ? ' (unit vendor)' : ''),
        ];
        if ($catatan !== '') {
            $bagian[] = $catatan;
        }

        return Str::limit(implode(' — ', $bagian), 255, '');
    }

    private function tanggalPalingAwal(): string
    {
        return now()->subDays(self::HARI_MUNDUR)->toDateString();
    }

    private function teksAtauNull(mixed $nilai): ?string
    {
        $teks = trim((string) ($nilai ?? ''));

        return $teks !== '' ? $teks : null;
    }

    private function tolak(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => $pesan]);
    }
}
