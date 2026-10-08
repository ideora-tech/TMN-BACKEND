<?php

declare(strict_types=1);

namespace App\Modules\Kasbon;

use App\Modules\ArusKas\ArusKasService;
use App\Modules\Kasbon\Contracts\KasbonRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KasbonService
{
    public const STATUS_MENUNGGU_APPROVAL  = 'menunggu_approval';
    public const STATUS_MENUNGGU_PENCAIRAN = 'menunggu_pencairan';
    public const STATUS_DITOLAK            = 'ditolak';
    public const STATUS_BERJALAN           = 'berjalan';
    public const STATUS_LUNAS              = 'lunas';

    public const STATUS_PENGAJUAN_BISA_DIUBAH = [
        ArusKasService::STATUS_MENUNGGU_APPROVAL,
        ArusKasService::STATUS_DITOLAK,
    ];

    public const PERAN_PELUNASAN = ['SUPERADMIN', 'KEUANGAN'];

    private const TOLERANSI = 0.004;

    public function __construct(
        private readonly KasbonRepositoryInterface $repo,
        private readonly ArusKasService $arusKasService,
    ) {}

    public static function sisaDari(object $kasbon): float
    {
        return max(0.0, round((float) $kasbon->nominal - (float) ($kasbon->terbayar ?? 0), 2));
    }

    public static function statusDari(object $kasbon): string
    {
        $dicairkan = (int) $kasbon->saldo_awal === 1
            || ($kasbon->status_pengajuan ?? null) === ArusKasService::STATUS_DITRANSFER;

        if ($dicairkan) {
            return self::sisaDari($kasbon) > 0 ? self::STATUS_BERJALAN : self::STATUS_LUNAS;
        }

        return match ($kasbon->status_pengajuan ?? null) {
            ArusKasService::STATUS_DITOLAK       => self::STATUS_DITOLAK,
            ArusKasService::STATUS_DISETUJUI,
            ArusKasService::STATUS_DICEK,
            ArusKasService::STATUS_SIAP_TRANSFER => self::STATUS_MENUNGGU_PENCAIRAN,
            default                              => self::STATUS_MENUNGGU_APPROVAL,
        };
    }

    public static function bisaDiubah(object $kasbon): bool
    {
        if ((int) $kasbon->saldo_awal === 1) {
            return (float) ($kasbon->terbayar ?? 0) <= 0;
        }

        return in_array($kasbon->status_pengajuan ?? null, self::STATUS_PENGAJUAN_BISA_DIUBAH, true);
    }

    public static function bisaUbahCicilan(object $kasbon): bool
    {
        return in_array(self::statusDari($kasbon), [self::STATUS_MENUNGGU_PENCAIRAN, self::STATUS_BERJALAN], true);
    }

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        ?string $status = null,
        ?string $idKaryawan = null,
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status, $idKaryawan);

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

    public function semua(string $idPerusahaan, ?string $search = null, ?string $status = null, ?string $idKaryawan = null): array
    {
        return $this->repo->semuaByPerusahaan($idPerusahaan, $search, $status, $idKaryawan);
    }

    public function ringkasan(string $idPerusahaan): array
    {
        $berjalan = $this->repo->berjalanByPerusahaan($idPerusahaan);

        return [
            'total_sisa'      => round(array_sum(array_map(static fn (object $k) => (float) $k->sisa, $berjalan)), 2),
            'jumlah_berjalan' => count($berjalan),
            'jumlah_karyawan' => count(array_unique(array_map(static fn (object $k) => (string) $k->id_karyawan, $berjalan))),
            'jumlah_menunggu' => $this->repo->hitungMenunggu($idPerusahaan),
        ];
    }

    public function opsiKaryawan(string $idPerusahaan): array
    {
        $rekap = [];
        foreach ($this->repo->berjalanByPerusahaan($idPerusahaan) as $kasbon) {
            $rekap[$kasbon->id_karyawan]['sisa']   = round(($rekap[$kasbon->id_karyawan]['sisa'] ?? 0) + (float) $kasbon->sisa, 2);
            $rekap[$kasbon->id_karyawan]['jumlah'] = ($rekap[$kasbon->id_karyawan]['jumlah'] ?? 0) + 1;
        }

        return array_map(static fn (object $k) => [
            'id_karyawan'            => $k->id_karyawan,
            'nama_karyawan'          => $k->nama_karyawan,
            'nik'                    => $k->nik,
            'nama_jabatan'           => $k->nama_jabatan,
            'nama_bank'              => $k->nama_bank,
            'nomor_rekening'         => $k->nomor_rekening,
            'aktif'                  => (int) $k->aktif === 1,
            'sisa_kasbon'            => (float) ($rekap[$k->id_karyawan]['sisa'] ?? 0),
            'jumlah_kasbon_berjalan' => (int) ($rekap[$k->id_karyawan]['jumlah'] ?? 0),
        ], $this->repo->opsiKaryawan($idPerusahaan));
    }

    public function findOrFail(string $id, string $idPerusahaan): object
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Kasbon tidak ditemukan');
        }

        return $record;
    }

    public function detail(string $id, string $idPerusahaan): object
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $record->pembayaran        = $this->repo->pembayaranByKasbon($id);
        $record->perubahan_cicilan = $this->repo->riwayatCicilanByKasbon($id);

        return $record;
    }

    public function riwayat(string $id, string $idPerusahaan): ?array
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        if ($record->id_pengajuan === null) {
            return null;
        }

        return $this->arusKasService->infoPengajuanById((string) $record->id_pengajuan, $idPerusahaan);
    }

    public function create(array $data, string $idPerusahaan): object
    {
        return DB::transaction(function () use ($data, $idPerusahaan) {
            $saldoAwal        = !empty($data['saldo_awal']);
            [$isi, $karyawan] = $this->rapikan($data, $idPerusahaan, $saldoAwal, null);
            $id               = (string) Str::uuid();
            $nomor            = $this->repo->nomorBerikutnya($idPerusahaan);

            $idPengajuan = null;
            if (!$saldoAwal) {
                $idPengajuan = $this->arusKasService->buatPengajuanKasbon(
                    $idPerusahaan,
                    $id,
                    $isi['nominal'],
                    $this->penerima($karyawan),
                    $this->keterangan($nomor, $isi['keperluan']),
                )->id_pengajuan;
            }

            return $this->repo->create(array_merge($isi, [
                'id_kasbon'     => $id,
                'id_perusahaan' => $idPerusahaan,
                'nomor_kasbon'  => $nomor,
                'saldo_awal'    => $saldoAwal ? 1 : 0,
                'id_pengajuan'  => $idPengajuan,
            ]));
        }, 3);
    }

    public function update(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $record = $this->kunciOrFail($id, $idPerusahaan);
            $this->pastikanBisaDiubah($record, 'diubah');

            $saldoAwal        = (int) $record->saldo_awal === 1;
            [$isi, $karyawan] = $this->rapikan($data, $idPerusahaan, $saldoAwal, $record);

            if (!$saldoAwal) {
                $this->arusKasService->perbaruiPengajuanKasbon(
                    (string) $record->id_pengajuan,
                    $idPerusahaan,
                    $isi['nominal'],
                    $this->penerima($karyawan),
                    $this->keterangan((string) $record->nomor_kasbon, $isi['keperluan']),
                );
            }

            return $this->repo->update($record, $isi);
        }, 3);
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $this->findOrFail($id, $idPerusahaan);

        DB::transaction(function () use ($id, $idPerusahaan) {
            $record = $this->kunciOrFail($id, $idPerusahaan);
            $this->pastikanBisaDiubah($record, 'dihapus');

            if ((int) $record->saldo_awal === 0) {
                $this->arusKasService->hapusPengajuanKasbon((string) $record->id_pengajuan, $idPerusahaan);
            }

            $this->repo->delete($record);
        }, 3);
    }

    public function ubahCicilan(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $record = $this->kunciOrFail($id, $idPerusahaan);
            if (!self::bisaUbahCicilan($record)) {
                abort(409, 'Cicilan hanya bisa diubah untuk kasbon yang sudah disetujui dan belum lunas');
            }

            $cicilan = round((float) $data['cicilan_per_periode'], 2);
            if ($cicilan > (float) $record->nominal + self::TOLERANSI) {
                $this->tolak('cicilan_per_periode', 'Cicilan tidak boleh melebihi nominal kasbon');
            }

            $cicilanLama = round((float) $record->cicilan_per_periode, 2);
            $mulaiLama   = substr((string) $record->mulai_potong, 0, 10);
            $mulai       = $this->tanggalMulaiPotong((string) $data['mulai_potong'], (string) $record->tanggal);
            if (abs($cicilan - $cicilanLama) < self::TOLERANSI && $mulai === $mulaiLama) {
                $this->tolak('cicilan_per_periode', 'Cicilan dan bulan mulai dipotong belum berubah');
            }

            $this->repo->update($record, [
                'cicilan_per_periode' => $cicilan,
                'mulai_potong'        => $mulai,
            ]);
            $this->repo->createRiwayatCicilan([
                'id_kasbon'         => $record->id_kasbon,
                'cicilan_lama'      => $cicilanLama,
                'cicilan_baru'      => $cicilan,
                'mulai_potong_lama' => $mulaiLama,
                'mulai_potong_baru' => $mulai,
                'alasan'            => trim((string) $data['alasan']),
            ]);

            return $this->detail($id, $idPerusahaan);
        }, 3);
    }

    public function catatPelunasan(string $id, array $data, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $data, $idPerusahaan) {
            $record = $this->kunciOrFail($id, $idPerusahaan);
            if (self::statusDari($record) !== self::STATUS_BERJALAN) {
                abort(409, 'Pelunasan hanya bisa dicatat untuk kasbon yang sedang berjalan');
            }

            $nominal = round((float) $data['nominal'], 2);
            $sisa    = self::sisaDari($record);
            if ($nominal > $sisa + self::TOLERANSI) {
                $this->tolak('nominal', 'Nominal melebihi sisa kasbon (Rp ' . $this->rupiah($sisa) . ')');
            }
            $palingAwal = max(substr((string) $record->tanggal, 0, 10), substr((string) ($record->tanggal_transfer ?? ''), 0, 10));
            if ((string) $data['tanggal'] < $palingAwal) {
                $this->tolak('tanggal', 'Tanggal pelunasan tidak boleh sebelum kasbon diberikan (' . date('d/m/Y', strtotime($palingAwal)) . ')');
            }

            $keterangan  = trim((string) ($data['keterangan'] ?? ''));
            $idPemasukan = null;
            if (empty($data['catat_pemasukan']) && $keterangan === '') {
                $this->tolak('keterangan', 'Keterangan wajib diisi bila pelunasan tidak dicatat sebagai pemasukan kas');
            }
            if (!empty($data['catat_pemasukan'])) {
                $nama        = trim((string) ($record->nama_karyawan ?? ''));
                $idPemasukan = $this->arusKasService->createPemasukan([
                    'kategori'    => 'pengembalian_dana',
                    'nominal'     => $nominal,
                    'tanggal'     => $data['tanggal'],
                    'sumber_dana' => Str::limit($nama !== '' ? $nama : 'Karyawan', 150, ''),
                    'keterangan'  => Str::limit(
                        "Pelunasan kasbon {$record->nomor_kasbon}" . ($keterangan !== '' ? " — {$keterangan}" : ''),
                        255,
                        '',
                    ),
                ], $idPerusahaan, null)->id_pemasukan;
            }

            $this->repo->createPembayaran([
                'id_kasbon'    => $record->id_kasbon,
                'tanggal'      => $data['tanggal'],
                'nominal'      => $nominal,
                'sumber'       => 'manual',
                'id_pemasukan' => $idPemasukan,
                'keterangan'   => $keterangan !== '' ? $keterangan : null,
            ]);

            return $this->detail($id, $idPerusahaan);
        }, 3);
    }

    public function hapusPelunasan(string $id, string $idPembayaran, string $idPerusahaan): object
    {
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $idPembayaran, $idPerusahaan) {
            $this->kunciOrFail($id, $idPerusahaan);

            $pembayaran = $this->repo->findPembayaran($idPembayaran, $id);
            if ($pembayaran === null) {
                abort(404, 'Pembayaran kasbon tidak ditemukan');
            }
            if ($pembayaran->sumber !== 'manual') {
                abort(409, 'Potongan gaji hanya bisa dibatalkan lewat Batal Finalisasi periode payroll');
            }

            $this->repo->deletePembayaran($pembayaran);
            if ($pembayaran->id_pemasukan !== null) {
                $this->arusKasService->hapusPemasukanKasbon((string) $pembayaran->id_pemasukan, $idPerusahaan);
            }

            return $this->detail($id, $idPerusahaan);
        }, 3);
    }

    /** @return array<string, array{sisa: float, rencana: float}> */
    public function rekapPotongan(string $idPerusahaan, string $tanggalSelesai, ?array $idKaryawan = null): array
    {
        $tanggalSelesai = substr($tanggalSelesai, 0, 10);
        $rekap          = [];

        foreach ($this->repo->berjalanByPerusahaan($idPerusahaan, $idKaryawan) as $kasbon) {
            $baris = $rekap[$kasbon->id_karyawan] ?? ['sisa' => 0.0, 'rencana' => 0.0];

            $rekap[$kasbon->id_karyawan] = [
                'sisa'    => round($baris['sisa'] + (float) $kasbon->sisa, 2),
                'rencana' => round($baris['rencana'] + $this->potonganTerjadwal($kasbon, $tanggalSelesai), 2),
            ];
        }

        return $rekap;
    }

    /** @return array<string, float> */
    public function rencanaPotongan(string $idPerusahaan, string $tanggalSelesai, ?array $idKaryawan = null): array
    {
        return array_map(
            static fn (array $baris) => $baris['rencana'],
            $this->rekapPotongan($idPerusahaan, $tanggalSelesai, $idKaryawan),
        );
    }

    public function sisaKaryawan(string $idPerusahaan, string $idKaryawan): float
    {
        return $this->rekapPotongan($idPerusahaan, now()->toDateString(), [$idKaryawan])[$idKaryawan]['sisa'] ?? 0.0;
    }

    /** @return array<string, float> */
    public function postingPotonganPayroll(object $periode, array $slips): array
    {
        $berkasbon = array_values(array_filter($slips, static fn (object $s) => round((float) ($s->kasbon ?? 0), 2) > 0));
        if ($berkasbon === []) {
            return [];
        }

        $idKaryawan  = array_values(array_unique(array_map(static fn (object $s) => (string) $s->id_karyawan, $berkasbon)));
        $perKaryawan = [];
        foreach ($this->repo->berjalanByPerusahaan((string) $periode->id_perusahaan, $idKaryawan, true) as $kasbon) {
            $perKaryawan[$kasbon->id_karyawan][] = $kasbon;
        }

        $tanggalSelesai = substr((string) $periode->tanggal_selesai, 0, 10);
        $tanggalPosting = now()->toDateString();
        $melebihi       = [];
        $minus          = [];
        $pembayaran     = [];
        $sisaSetelah    = [];

        foreach ($berkasbon as $slip) {
            $daftar    = $perKaryawan[$slip->id_karyawan] ?? [];
            $potong    = round((float) $slip->kasbon, 2);
            $totalSisa = round(array_sum(array_map(static fn (object $k) => (float) $k->sisa, $daftar)), 2);
            $nama      = (string) ($slip->nama_karyawan ?? '-');

            if ($potong > $totalSisa + self::TOLERANSI) {
                $melebihi[] = sprintf('%s (potongan Rp %s, sisa kasbon Rp %s)', $nama, $this->rupiah($potong), $this->rupiah($totalSisa));
                continue;
            }
            if ((float) $slip->gaji_bersih < 0) {
                $minus[] = $nama;
                continue;
            }

            foreach ($this->alokasikan($daftar, $potong, $tanggalSelesai) as $idKasbon => $nominal) {
                $pembayaran[] = [
                    'id_kasbon'  => $idKasbon,
                    'tanggal'    => $tanggalPosting,
                    'nominal'    => $nominal,
                    'sumber'     => 'payroll',
                    'id_periode' => $periode->id_periode,
                    'id_slip'    => $slip->id_slip,
                    'keterangan' => Str::limit('Potong gaji ' . $periode->nama, 255, ''),
                ];
            }

            $sisaSetelah[(string) $slip->id_slip] = round(array_sum(array_map(static fn (object $k) => (float) $k->sisa, $daftar)), 2);
        }

        if ($melebihi !== []) {
            abort(422, 'Potongan kasbon di slip melebihi sisa kasbon di menu Kasbon: ' . $this->ringkas($melebihi)
                . '. Catat kasbon lama sebagai Kasbon Lama di menu Kasbon, atau koreksi potongan kasbon pada slipnya.');
        }
        if ($minus !== []) {
            abort(422, 'Gaji bersih minus pada slip yang memotong kasbon: ' . $this->ringkas($minus)
                . '. Kurangi potongan kasbon pada slip tersebut.');
        }

        foreach ($pembayaran as $baris) {
            $this->repo->createPembayaran($baris);
        }

        return $sisaSetelah;
    }

    public function batalkanPotonganPayroll(string $idPeriode): void
    {
        $this->repo->hapusPembayaranPayroll($idPeriode);
    }

    /**
     * @param list<object> $daftar
     * @return array<string, float>
     */
    private function alokasikan(array $daftar, float $potong, string $tanggalSelesai): array
    {
        $alokasi = [];
        $sisa    = $potong;

        foreach ($daftar as $kasbon) {
            if ($sisa <= 0) {
                break;
            }
            $jatah = min($this->potonganTerjadwal($kasbon, $tanggalSelesai), $sisa);
            if ($jatah > 0) {
                $alokasi[$kasbon->id_kasbon] = $jatah;
                $sisa = round($sisa - $jatah, 2);
            }
        }

        foreach ($daftar as $kasbon) {
            if ($sisa <= 0) {
                break;
            }
            $jatah = min(round((float) $kasbon->sisa - ($alokasi[$kasbon->id_kasbon] ?? 0), 2), $sisa);
            if ($jatah > 0) {
                $alokasi[$kasbon->id_kasbon] = round(($alokasi[$kasbon->id_kasbon] ?? 0) + $jatah, 2);
                $sisa = round($sisa - $jatah, 2);
            }
        }

        foreach ($daftar as $kasbon) {
            if (isset($alokasi[$kasbon->id_kasbon])) {
                $kasbon->sisa = round((float) $kasbon->sisa - $alokasi[$kasbon->id_kasbon], 2);
            }
        }

        return $alokasi;
    }

    private function potonganTerjadwal(object $kasbon, string $tanggalSelesai): float
    {
        $sisa = (float) $kasbon->sisa;
        if ($sisa <= 0) {
            return 0.0;
        }
        if ((int) ($kasbon->karyawan_aktif ?? 1) === 0) {
            return $sisa;
        }
        if (substr((string) $kasbon->mulai_potong, 0, 10) > $tanggalSelesai) {
            return 0.0;
        }

        return min((float) $kasbon->cicilan_per_periode, $sisa);
    }

    private function kunciOrFail(string $id, string $idPerusahaan): object
    {
        $this->repo->kunci([$id]);

        return $this->findOrFail($id, $idPerusahaan);
    }

    private function pastikanBisaDiubah(object $record, string $aksi): void
    {
        if (self::bisaDiubah($record)) {
            return;
        }

        if ((int) $record->saldo_awal === 1) {
            abort(409, "Kasbon lama yang sudah punya pembayaran tidak bisa {$aksi}");
        }

        abort(409, "Kasbon hanya bisa {$aksi} saat status menunggu approval atau ditolak (status saat ini: "
            . str_replace('_', ' ', self::statusDari($record)) . ')');
    }

    /** @return array{0: array<string, mixed>, 1: object} */
    private function rapikan(array $data, string $idPerusahaan, bool $saldoAwal, ?object $lama): array
    {
        $karyawan = $this->repo->findKaryawan((string) $data['id_karyawan'], $idPerusahaan)
            ?? $this->tolak('id_karyawan', 'Karyawan tidak ditemukan');

        $karyawanBaru = $lama === null || $lama->id_karyawan !== $karyawan->id_karyawan;
        if (!$saldoAwal && $karyawanBaru && (int) $karyawan->aktif !== 1) {
            $this->tolak('id_karyawan', 'Karyawan sudah tidak aktif');
        }

        $nominal = round((float) $data['nominal'], 2);
        $cicilan = round((float) $data['cicilan_per_periode'], 2);
        if ($cicilan > $nominal) {
            $this->tolak('cicilan_per_periode', 'Cicilan tidak boleh melebihi nominal kasbon');
        }

        $bank     = trim((string) ($data['nama_bank'] ?? ''));
        $rekening = trim((string) ($data['nomor_rekening'] ?? ''));

        return [[
            'id_karyawan'         => $karyawan->id_karyawan,
            'tanggal'             => $data['tanggal'],
            'nominal'             => $nominal,
            'cicilan_per_periode' => $cicilan,
            'mulai_potong'        => $this->tanggalMulaiPotong((string) $data['mulai_potong'], (string) $data['tanggal']),
            'keperluan'           => trim((string) $data['keperluan']),
            'nama_bank'           => $bank !== '' ? $bank : null,
            'nomor_rekening'      => $rekening !== '' ? $rekening : null,
        ], $karyawan];
    }

    private function tanggalMulaiPotong(string $bulan, string $tanggalKasbon): string
    {
        $mulai = $bulan . '-01';
        if ($mulai < substr($tanggalKasbon, 0, 7) . '-01') {
            $this->tolak('mulai_potong', 'Mulai dipotong tidak boleh sebelum bulan kasbon');
        }

        $tahunMaks = (int) now()->format('Y') + 2;
        if ($mulai > $tahunMaks . '-12-01') {
            $this->tolak('mulai_potong', "Mulai dipotong paling lambat Desember {$tahunMaks}");
        }

        return $mulai;
    }

    private function penerima(object $karyawan): string
    {
        return Str::limit((string) $karyawan->nama_karyawan, 150, '');
    }

    private function keterangan(string $nomor, string $keperluan): string
    {
        return Str::limit("Kasbon {$nomor} — {$keperluan}", 255, '');
    }

    private function rupiah(float $nilai): string
    {
        return number_format($nilai, 0, ',', '.');
    }

    /** @param list<string> $daftar */
    private function ringkas(array $daftar): string
    {
        $tampil = array_slice($daftar, 0, 5);
        $lebih  = count($daftar) - count($tampil);

        return implode('; ', $tampil) . ($lebih > 0 ? "; dan {$lebih} lainnya" : '');
    }

    private function tolak(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => $pesan]);
    }
}
