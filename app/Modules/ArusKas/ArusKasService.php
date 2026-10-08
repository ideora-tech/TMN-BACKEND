<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use App\Modules\Notifikasi\NotifikasiService;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArusKasService
{
    public const STATUS_DIAJUKAN          = 'diajukan';
    public const STATUS_DICEK             = 'dicek';
    public const STATUS_SIAP_TRANSFER     = 'siap_transfer';
    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';
    public const STATUS_DISETUJUI         = 'disetujui';
    public const STATUS_DITOLAK           = 'ditolak';
    public const STATUS_DITRANSFER        = 'ditransfer';

    public const KUNCI_BATAS_APPROVAL = 'batas_approval_keuangan';

    public const KUNCI_WAJIB_APPROVAL_MANUAL = 'wajib_approval_pengajuan_manual';

    public const KUNCI_BATAS_REALISASI_MANDIRI = 'batas_realisasi_mandiri_sparepart';

    public const DEFAULT_BATAS_REALISASI_MANDIRI = 500_000.0;

    public const KODE_PERSETUJUAN_TRANSFER = 'persetujuan_transfer';

    public const RIWAYAT_DITOLAK        = 'ditolak';
    public const RIWAYAT_DIAJUKAN_ULANG = 'diajukan_ulang';

    public const PERAN_KEUANGAN = ['SUPERADMIN', 'KEUANGAN'];

    private const LABEL_KATEGORI = [
        'uang_jalan'          => 'Uang Jalan',
        'legalitas'           => 'Legalitas',
        'perawatan'           => 'Perawatan',
        'sparepart'           => 'Sparepart',
        'penggajian'          => 'Penggajian',
        'pembelian_aset'      => 'Pembelian Aset',
        'pembayaran_pinjaman' => 'Pembayaran Pinjaman',
        'pembayaran_vendor'   => 'Pembayaran Vendor',
        'pengadaan'           => 'Pengadaan',
        'kasbon'              => 'Kasbon',
        'lainnya'             => 'Lainnya',
    ];

    private const URUTAN_RIWAYAT = [
        self::RIWAYAT_DIAJUKAN_ULANG => 0,
        'disetujui'                  => 1,
        'disetujui_final'            => 2,
        self::STATUS_DICEK           => 3,
    ];

    public const KODE_EVENT_PENGELUARAN = [
        'pengajuan_pengeluaran',
        'uang_jalan',
        'legalitas',
        'perawatan',
        'sparepart',
        'penggajian',
        'pembelian_aset',
        'pembayaran_pinjaman',
        'pembayaran_vendor',
        'pengadaan',
        'kasbon',
        'lainnya',
    ];

    public function __construct(
        private readonly ArusKasRepositoryInterface $repo,
        private readonly NotifikasiService $notifikasiService,
        private readonly \App\Modules\Approval\ApprovalService $approvalService,
        private readonly \App\Modules\PembayaranVendor\Contracts\PembayaranVendorRepositoryInterface $pembayaranVendorRepo,
    ) {}

    public function infoPengajuanTrip(string $idTrip): ?array
    {
        $record = $this->repo->findPengajuanByTrip($idTrip)
            ?? $this->repo->findPengajuanPeriodeUntukTrip($idTrip);
        if ($record === null) {
            return null;
        }
        return $this->susunInfoPengajuan($record);
    }

    public function infoPengajuanPembelian(string $idPembelian): ?array
    {
        $record = $this->repo->findPengajuanByPembelian($idPembelian);
        if ($record === null) {
            return null;
        }
        return $this->susunInfoPengajuan($record);
    }

    public function infoPengajuanPerawatan(string $idPerawatan): ?array
    {
        $record = $this->repo->findPengajuanByPerawatan($idPerawatan);
        if ($record === null) {
            return null;
        }
        return $this->susunInfoPengajuan($record);
    }

    public function infoPengajuanPeriode(string $idPeriode): ?array
    {
        $record = $this->repo->findPengajuanByPeriode($idPeriode);
        if ($record === null) {
            return null;
        }
        return $this->susunInfoPengajuan($record);
    }

    public function infoPengajuanPermintaanPembelian(string $idPermintaan): ?array
    {
        $record = $this->repo->findPengajuanByPermintaanPembelian($idPermintaan);
        if ($record === null) {
            return null;
        }
        return $this->susunInfoPengajuan($record);
    }

    private function susunInfoPengajuan(PengajuanPengeluaranModel $record): array
    {
        $namaMap = $this->repo->namaPengguna(array_values(array_filter([
            $record->dibuat_oleh,
            $record->dicek_oleh,
            $record->disetujui_oleh,
            $record->ditransfer_oleh,
            $record->diubah_oleh,
        ])));

        $riwayat = [[
            'status'     => self::STATUS_DIAJUKAN,
            'waktu'      => $record->dibuat_pada,
            'oleh'       => $record->dibuat_oleh !== null ? ($namaMap[$record->dibuat_oleh] ?? null) : null,
            'keterangan' => $record->keterangan,
            '_urutan'    => 0,
        ]];

        $semuaApproval = $this->repo->listApproval((string) $record->id_pengajuan);
        foreach ($semuaApproval as $baris) {
            if ($baris['waktu_aksi'] === null) {
                continue;
            }
            $dariGerbangTransfer = ($baris['kode_event'] ?? null) === self::KODE_PERSETUJUAN_TRANSFER;
            $riwayat[] = [
                'status'     => $dariGerbangTransfer ? $baris['status'] . '_transfer' : $baris['status'],
                'waktu'      => $baris['waktu_aksi'],
                'oleh'       => $baris['nama'],
                'keterangan' => $baris['catatan'],
                '_urutan'    => $dariGerbangTransfer ? 4 : 1,
            ];
        }

        if ($record->disetujui_pada !== null) {
            $riwayat[] = [
                'status'     => 'disetujui_final',
                'waktu'      => $record->disetujui_pada,
                'oleh'       => $record->disetujui_oleh !== null ? ($namaMap[$record->disetujui_oleh] ?? null) : null,
                'keterangan' => null,
                '_urutan'    => 2,
            ];
        }

        if ($record->dicek_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DICEK,
                'waktu'      => $record->dicek_pada,
                'oleh'       => $record->dicek_oleh !== null ? ($namaMap[$record->dicek_oleh] ?? null) : null,
                'keterangan' => null,
                '_urutan'    => 3,
            ];
        }

        $riwayatTercatat = $this->repo->listRiwayatPengajuan((string) $record->id_pengajuan);
        $namaTercatat = $this->repo->namaPengguna(array_values(array_unique(array_filter(
            array_map(fn ($baris) => $baris->oleh, $riwayatTercatat)
        ))));
        foreach ($riwayatTercatat as $baris) {
            $ditolak = $baris->jenis === self::RIWAYAT_DITOLAK;
            $riwayat[] = [
                'status'     => $ditolak ? 'ditolak_final' : $baris->jenis,
                'waktu'      => $baris->waktu,
                'oleh'       => $baris->oleh !== null ? ($namaTercatat[$baris->oleh] ?? null) : null,
                'keterangan' => $baris->keterangan,
                '_urutan'    => self::URUTAN_RIWAYAT[$baris->jenis] ?? 4,
            ];
        }
        $penolakanTercatat = $riwayatTercatat !== [] && end($riwayatTercatat)->jenis === self::RIWAYAT_DITOLAK;

        if ($record->status === self::STATUS_DITOLAK && !$penolakanTercatat) {
            $riwayat[] = [
                'status'     => 'ditolak_final',
                'waktu'      => $record->diubah_pada,
                'oleh'       => $record->diubah_oleh !== null ? ($namaMap[$record->diubah_oleh] ?? null) : null,
                'keterangan' => $record->alasan_ditolak,
                '_urutan'    => 4,
            ];
        }

        if ($record->ditransfer_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DITRANSFER,
                'waktu'      => $record->ditransfer_pada,
                'oleh'       => $record->ditransfer_oleh !== null ? ($namaMap[$record->ditransfer_oleh] ?? null) : null,
                'keterangan' => $record->tanggal_transfer !== null ? 'Tanggal transfer ' . $record->tanggal_transfer : null,
                '_urutan'    => 4,
            ];
        }

        usort($riwayat, function (array $a, array $b) {
            $bandingWaktu = strcmp((string) $a['waktu'], (string) $b['waktu']);
            return $bandingWaktu !== 0 ? $bandingWaktu : $a['_urutan'] <=> $b['_urutan'];
        });

        $riwayat = array_map(function (array $entri) {
            unset($entri['_urutan']);
            return $entri;
        }, $riwayat);

        $tahapMenunggu = match ($record->status) {
            self::STATUS_MENUNGGU_APPROVAL => 'approval',
            self::STATUS_DICEK             => 'transfer',
            default                        => null,
        };
        $namaMenunggu = [];
        if ($tahapMenunggu !== null) {
            foreach ($semuaApproval as $baris) {
                $dariGerbangTransfer = ($baris['kode_event'] ?? null) === self::KODE_PERSETUJUAN_TRANSFER;
                if ($baris['waktu_aksi'] === null && $baris['status'] === 'menunggu'
                    && ($baris['status_approval'] ?? 'menunggu') === 'menunggu'
                    && $dariGerbangTransfer === ($tahapMenunggu === 'transfer')) {
                    $namaMenunggu[] = $baris['nama'];
                }
            }
        }
        $namaMenunggu = array_values(array_filter($namaMenunggu));

        return [
            'id_pengajuan'      => $record->id_pengajuan,
            'nomor_pengajuan'   => $record->nomor_pengajuan,
            'kategori'          => $record->kategori,
            'status'            => $record->status,
            'alasan_ditolak'    => $record->status === self::STATUS_DITOLAK ? $record->alasan_ditolak : null,
            'versi'             => (string) ($record->diubah_pada ?? ''),
            'nominal'           => (float) $record->nominal,
            'tanggal_pengajuan' => $record->tanggal_pengajuan,
            'tanggal_transfer'  => $record->tanggal_transfer,
            'url_bukti'         => PenyimpananBerkas::url($record->url_bukti),
            'menunggu'          => $namaMenunggu !== [] ? ['tahap' => $tahapMenunggu, 'nama' => $namaMenunggu] : null,
            'riwayat'           => $riwayat,
            'periode'         => $record->periode_dari !== null ? [
                'dari'           => $record->periode_dari,
                'sampai'         => $record->periode_sampai,
                'tarif_per_hari' => (float) $record->tarif_per_hari,
                'jumlah_hari'    => (int) round((float) $record->nominal / (float) $record->tarif_per_hari),
            ] : null,
        ];
    }

    public function infoPengajuanById(string $id, string $idPerusahaan): array
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        return $this->susunInfoPengajuan($record);
    }

    /**
     * Rincian uang jalan: daftar penugasan harian yang dibiayai pengajuan ini.
     * `jumlah_hari_ditagih` dihitung dari nominal saat pengajuan dibuat, sedangkan
     * `penugasan` dibaca ulang sekarang — kalau ada yang dibatalkan setelah
     * pengajuan dibuat, selisihnya memang harus terlihat oleh keuangan.
     */
    public function rincianUangJalan(PengajuanPengeluaranModel $record): array
    {
        $penugasan = $this->repo->penugasanUntukPengajuan((string) $record->id_pengajuan);
        $tarif = $record->tarif_per_hari !== null ? (float) $record->tarif_per_hari : null;

        $info = $record->id_supir !== null && $record->id_proyek !== null
            ? $this->repo->dataUntukPengajuanPenugasan((string) $record->id_supir, (string) $record->id_proyek)
            : null;

        return [
            'nama_supir'          => $info?->nama_supir,
            'nama_proyek'         => $info?->nama_proyek,
            'periode_dari'        => $record->periode_dari,
            'periode_sampai'      => $record->periode_sampai,
            'tarif_per_hari'      => $tarif,
            'jumlah_hari_ditagih' => $tarif !== null && $tarif > 0
                ? (int) round((float) $record->nominal / $tarif)
                : null,
            'jumlah_penugasan'    => count($penugasan),
            'jumlah_dibatalkan'   => count(array_filter($penugasan, static fn ($p) => $p->status === 'batal')),
            'penugasan'           => array_map(static fn ($p) => [
                'id_penugasan'  => $p->id_penugasan,
                'tanggal_tugas' => $p->tanggal_tugas,
                'status'        => $p->status,
                'sumber'        => $p->sumber,
                'keterangan'    => $p->keterangan,
                'kode_proyek'   => $p->kode_proyek,
                'nama_proyek'   => $p->nama_proyek,
                'nama_rute'     => $p->nama_rute,
                'nopol'         => $p->nopol,
            ], $penugasan),
        ];
    }

    public function menungguApprovalSaya(string $idPerusahaan, string $idPengguna): array
    {
        $records = $this->repo->listMenungguApprovalSaya($idPerusahaan, $idPengguna);
        $this->lampirkanApprovalBanyak($records, $idPengguna);

        return [
            'pengajuan' => $records,
            'ringkasan' => [
                'jumlah'        => $records->count(),
                'total_nominal' => (float) $records->sum('nominal'),
            ],
        ];
    }

    public function lampirkanApproval(object $record, string $idPenggunaLogin): void
    {
        $this->setAtributApproval($record, $this->repo->listApproval((string) $record->id_pengajuan), $idPenggunaLogin);
    }

    public function lampirkanApprovalBanyak(iterable $records, string $idPenggunaLogin): void
    {
        $records = is_array($records) ? $records : iterator_to_array($records);
        $idPengajuanList = array_values(array_unique(array_map(fn ($record) => (string) $record->id_pengajuan, $records)));
        $approvalMap = $this->repo->listApprovalBanyak($idPengajuanList);

        foreach ($records as $record) {
            $approval = $approvalMap[(string) $record->id_pengajuan] ?? [];
            $this->setAtributApproval($record, $approval, $idPenggunaLogin);
        }
    }

    private function setAtributApproval(object $record, array $approvalMentah, string $idPenggunaLogin): void
    {
        $petakan = fn (array $baris) => [
            'id_pengguna' => $baris['id_pengguna'],
            'nama'        => $baris['nama'],
            'status'      => $baris['status'],
            'catatan'     => $baris['catatan'],
            'waktu_aksi'  => $baris['waktu_aksi'],
        ];

        $idPutaranTerakhir = $record->status === self::STATUS_DITOLAK && $approvalMentah !== []
            ? ($approvalMentah[array_key_last($approvalMentah)]['id_approval'] ?? null)
            : null;
        $putaranTerkini = fn (array $baris) => in_array($baris['status_approval'] ?? null, ['menunggu', 'disetujui'], true)
            || ($idPutaranTerakhir !== null && ($baris['id_approval'] ?? null) === $idPutaranTerakhir);
        $approvalMentah = array_values(array_filter($approvalMentah, $putaranTerkini));

        $barisTransfer = array_values(array_filter(
            $approvalMentah,
            fn (array $baris) => ($baris['kode_event'] ?? null) === self::KODE_PERSETUJUAN_TRANSFER,
        ));
        $approvalMentah = array_values(array_filter(
            $approvalMentah,
            fn (array $baris) => ($baris['kode_event'] ?? null) !== self::KODE_PERSETUJUAN_TRANSFER,
        ));

        $approval = array_map($petakan, $approvalMentah);

        $record->approval          = $approval;
        $record->approval_transfer = array_map($petakan, $barisTransfer);
        $record->approval_progress = $approval === [] ? null : [
            'disetujui' => count(array_filter($approval, fn (array $baris) => $baris['status'] === 'disetujui')),
            'total'     => count($approval),
        ];
        $record->bisa_approve = $record->status === self::STATUS_MENUNGGU_APPROVAL
            && collect($approval)->contains(fn (array $baris) => $baris['id_pengguna'] === $idPenggunaLogin && $baris['status'] === 'menunggu');
    }

    public function listPengajuan(string $idPerusahaan, ?string $status = null, ?string $search = null, ?string $kategori = null): array
    {
        return $this->repo->listPengajuanByPerusahaan($idPerusahaan, $status, $search, $kategori)->all();
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
            $data['nomor_pengajuan'] = $this->repo->nomorPengajuanBerikutnya($idPerusahaan);
            if ($bukti !== null) {
                $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
            }

            if ($this->wajibApprovalManual($idPerusahaan)) {
                $data['status'] = self::STATUS_DIAJUKAN;
                $record = $this->repo->createPengajuan($data);
                return $this->masukTahapApproval($record);
            }

            $data['status']         = self::STATUS_DISETUJUI;
            $data['disetujui_oleh'] = auth()->id() ?? null;
            $data['disetujui_pada'] = now();

            $record = $this->repo->createPengajuan($data);
            $this->jalankanHookSetujui($record);
            return $record;
        });
    }

    public function updatePengajuan(string $id, array $data, string $idPerusahaan, ?UploadedFile $bukti): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        if ($record->id_uang_jalan !== null) {
            abort(422, 'Pengajuan uang jalan diubah lewat menu Uang Jalan');
        }
        if ($record->id_kasbon !== null) {
            abort(422, 'Pengajuan kasbon diubah lewat menu Kasbon');
        }
        if ($record->id_permintaan_pembelian !== null) {
            abort(422, 'Pengajuan pembayaran PR diubah lewat halaman Permintaan Pembelian (Ajukan Ulang Pembayaran)');
        }
        $this->pastikanStatus($record, [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DITOLAK], 'Pengajuan hanya bisa diubah saat status menunggu approval atau ditolak');
        if ($bukti !== null) {
            $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
        }

        return DB::transaction(function () use ($record, $data) {
            $statusAwal  = $record->status;
            $nominalLama = (float) $record->nominal;
            if ($statusAwal === self::STATUS_DITOLAK) {
                $this->pastikanPenolakanTercatat($record);
            }
            $updated     = $this->repo->updatePengajuan($record, $data);

            if ($statusAwal === self::STATUS_MENUNGGU_APPROVAL) {
                return $this->resetSnapshotApproval($updated, $nominalLama);
            }

            $this->catatDiajukanUlang($updated);

            return $this->masukTahapApproval($updated);
        });
    }

    public function deletePengajuan(string $id, string $idPerusahaan): void
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        if ($record->id_termin_pembelian !== null) {
            abort(422, 'Pengajuan termin PR aset tidak bisa dihapus; batalkan lewat modul Permintaan Pembelian');
        }
        if ($record->id_uang_jalan !== null) {
            abort(422, 'Pengajuan uang jalan dihapus lewat menu Uang Jalan');
        }
        if ($record->id_kasbon !== null) {
            abort(422, 'Pengajuan kasbon dihapus lewat menu Kasbon');
        }
        if ($record->id_permintaan_pembelian !== null) {
            abort(422, 'Pengajuan pembayaran PR tidak bisa dihapus — bila ditolak, ajukan ulang dari halaman Permintaan Pembelian');
        }
        $this->pastikanStatus($record, [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DITOLAK], 'Pengajuan hanya bisa dihapus saat status menunggu approval atau ditolak');

        $this->approvalService->batalkanUntukReferensi(
            [...self::KODE_EVENT_PENGELUARAN, self::KODE_PERSETUJUAN_TRANSFER],
            (string) $record->id_pengajuan,
            (string) $record->id_perusahaan,
        );

        $this->repo->deletePengajuan($record);
        $this->repo->unlinkJadwalPengajuan($id);
    }

    public function cek(string $id, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI], 'Pengajuan tidak bisa diverifikasi dari status saat ini');

        return DB::transaction(function () use ($id, $idPerusahaan) {
            $terkunci = $this->repo->findPengajuanForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Pengajuan pengeluaran tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_DISETUJUI], 'Pengajuan tidak bisa diverifikasi dari status saat ini');

            $aktor = auth()->id();
            $diperbarui = $this->repo->updatePengajuan($terkunci, [
                'status'     => self::STATUS_DICEK,
                'dicek_oleh' => $aktor,
                'dicek_pada' => now(),
            ]);

            if ($this->approvalService->adaEventTypeAktif(self::KODE_PERSETUJUAN_TRANSFER, $idPerusahaan)) {
                $this->approvalService->ajukan(
                    self::KODE_PERSETUJUAN_TRANSFER,
                    (string) $diperbarui->id_pengajuan,
                    (string) $aktor,
                    (float) $diperbarui->nominal,
                    $idPerusahaan,
                );
                return $diperbarui;
            }

            $siap = $this->repo->updatePengajuan($diperbarui, ['status' => self::STATUS_SIAP_TRANSFER]);
            $this->beritahuKeuanganSiapTransfer($siap);

            return $siap;
        });
    }

    private function masukTahapApproval(PengajuanPengeluaranModel $record, ?string $aktorId = null): PengajuanPengeluaranModel
    {
        $aktor = $aktorId ?? auth()->id() ?? $record->dibuat_oleh;
        $batas = $this->batasApproval((string) $record->id_perusahaan);
        if ((float) $record->nominal < $batas) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'          => self::STATUS_DISETUJUI,
                'disetujui_oleh'  => $aktor,
                'disetujui_pada'  => now(),
            ]);
            $this->jalankanHookSetujui($updated);
            return $updated;
        }

        $kode = $this->kodeApprovalUntuk($record);
        if ($kode === null) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'          => self::STATUS_DISETUJUI,
                'disetujui_oleh'  => $aktor,
                'disetujui_pada'  => now(),
            ]);
            $this->jalankanHookSetujui($updated);
            return $updated;
        }

        $this->approvalService->ajukan(
            $kode,
            (string) $record->id_pengajuan,
            (string) $record->dibuat_oleh,
            (float) $record->nominal,
            (string) $record->id_perusahaan,
        );

        return $this->repo->updatePengajuan($record, ['status' => self::STATUS_MENUNGGU_APPROVAL]);
    }

    private function kodeApprovalUntuk(PengajuanPengeluaranModel $record): ?string
    {
        $kategori     = (string) $record->kategori;
        $idPerusahaan = (string) $record->id_perusahaan;

        if ($this->approvalService->adaEventTypeAktif($kategori, $idPerusahaan)) {
            return $kategori;
        }
        if ($this->approvalService->eventTypeDinonaktifkan($kategori, $idPerusahaan)) {
            return null;
        }

        return $this->approvalService->adaEventTypeAktif('pengajuan_pengeluaran', $idPerusahaan)
            ? 'pengajuan_pengeluaran'
            : null;
    }

    private function resetSnapshotApproval(PengajuanPengeluaranModel $record, float $nominalLama): PengajuanPengeluaranModel
    {
        $this->approvalService->batalkanUntukReferensi(
            [...self::KODE_EVENT_PENGELUARAN, self::KODE_PERSETUJUAN_TRANSFER],
            (string) $record->id_pengajuan,
            (string) $record->id_perusahaan,
        );

        $batas = $this->batasApproval((string) $record->id_perusahaan);
        if ((float) $record->nominal < $batas) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DISETUJUI,
                'disetujui_oleh' => auth()->id() ?? $record->dibuat_oleh,
                'disetujui_pada' => now(),
            ]);
            $this->jalankanHookSetujui($updated);
            return $updated;
        }

        $kode = $this->kodeApprovalUntuk($record);
        if ($kode === null) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DISETUJUI,
                'disetujui_oleh' => auth()->id() ?? $record->dibuat_oleh,
                'disetujui_pada' => now(),
            ]);
            $this->jalankanHookSetujui($updated);
            return $updated;
        }

        $pengajuanBaru = $this->approvalService->ajukan(
            $kode,
            (string) $record->id_pengajuan,
            (string) $record->dibuat_oleh,
            (float) $record->nominal,
            (string) $record->id_perusahaan,
        );

        $idApproverBaru = DB::table('approval_keputusan')->where('id_approval', $pengajuanBaru->id_approval)->pluck('id_pengguna');
        foreach ($idApproverBaru as $idPengguna) {
            $this->notifikasiService->buatDanKirim([
                'id_perusahaan'  => (string) $record->id_perusahaan,
                'id_pengguna'    => $idPengguna,
                'judul'          => 'Pengajuan pengeluaran perlu approval ulang',
                'isi'            => round($nominalLama, 2) === round((float) $record->nominal, 2)
                    ? sprintf('Data pengajuan %s diubah — perlu approval ulang', $record->nomor_pengajuan)
                    : sprintf(
                        'Nominal pengajuan %s berubah dari Rp %s menjadi Rp %s — perlu approval ulang',
                        $record->nomor_pengajuan,
                        number_format($nominalLama, 0, ',', '.'),
                        number_format((float) $record->nominal, 0, ',', '.'),
                    ),
                'tipe'           => 'approval_keuangan',
                'referensi_id'   => (string) $record->id_pengajuan,
                'referensi_tipe' => 'pengajuan_pengeluaran',
                'link'           => \App\Support\LinkReferensiApproval::menungguSaya((string) $pengajuanBaru->id_approval),
                'dibaca'         => 0,
            ]);
        }

        return $this->repo->updatePengajuan($record, ['status' => self::STATUS_MENUNGGU_APPROVAL]);
    }

    private function jalankanHookSetujui(PengajuanPengeluaranModel $record): void
    {
        if ($record->id_pembelian !== null) {
            $this->repo->sinkronPembelianSetujui($record->id_pembelian);
        }
        $this->beritahuKeuangan($record, 'verifikasi');
        if ($record->id_permintaan_pembelian !== null) {
            $this->beritahuTimPelaksana($record, 'pengadaan_disetujui',
                'disetujui',
                'sudah disetujui dan menunggu verifikasi Keuangan.');
            return;
        }
        $this->beritahuTimPelaksana($record, 'pengadaan_disetujui',
            'disetujui — siap direalisasi',
            'sudah disetujui. Silakan lanjutkan realisasi/pembelian.');
    }
    private function jalankanHookTolak(PengajuanPengeluaranModel $record, string $alasan): void
    {
        if ($record->id_pembelian !== null) {
            $this->repo->sinkronPembelianTolak($record->id_pembelian, $alasan);
        }
        $this->beritahuTimPelaksana($record, 'pengadaan_ditolak',
            'ditolak',
            'ditolak' . ($alasan !== '' ? ": {$alasan}" : '') . ($record->id_permintaan_pembelian !== null
                ? '. Perbaiki lalu ajukan ulang pembayarannya dari halaman PR.'
                : '. Periksa lalu ajukan ulang bila perlu.'));
        if ($record->id_invoice_vendor !== null) {
            $this->beritahuPembayaranVendorDitolak($record, $alasan);
        }
    }

    private function beritahuPembayaranVendorDitolak(PengajuanPengeluaranModel $record, string $alasan): void
    {
        $invoice = $this->pembayaranVendorRepo->infoInvoiceUntukPengajuan((string) $record->id_invoice_vendor, (string) $record->id_perusahaan);
        if ($invoice === null) {
            return;
        }

        $nominal = number_format((float) $record->nominal, 0, ',', '.');
        $alasan  = trim($alasan);
        $penolak = auth()->id();

        $this->notifikasiService->kirimKePemilikIzinMenu(
            ['/invoice-vendor'],
            (string) $record->id_perusahaan,
            "Pembayaran invoice vendor {$invoice->nomor_invoice} ditolak",
            "Pengajuan {$record->nomor_pengajuan} (Rp {$nominal}) untuk invoice {$invoice->nomor_invoice} ditolak"
                . ($alasan !== '' ? ': ' . Str::limit($alasan, 200) : '')
                . '. Invoice tetap aktif — periksa lalu ajukan pembayaran ulang.',
            'pembayaran_vendor_ditolak',
            'invoice_vendor',
            (string) $record->id_invoice_vendor,
            '/invoice-vendor/' . $record->id_invoice_vendor,
            $penolak !== null ? (string) $penolak : null,
            'tambah',
        );
    }

    private function beritahuTimPelaksana(PengajuanPengeluaranModel $record, string $tipe, string $judulAkhir, string $isiAkhir): void
    {
        [$menu, $referensiTipe, $referensiId, $link] = match (true) {
            $record->id_permintaan_pembelian !== null => [['/permintaan-pembelian'], 'permintaan_pembelian', (string) $record->id_permintaan_pembelian, '/permintaan-pembelian?detail=' . $record->id_permintaan_pembelian],
            $record->id_pembelian !== null => [['/pembelian-sparepart'], 'pembelian_sparepart', (string) $record->id_pembelian, '/pembelian-sparepart/' . $record->id_pembelian],
            $record->id_perawatan !== null => [['/perawatan-armada'], 'perawatan_armada', (string) $record->id_perawatan, '/perawatan-armada?detail=' . $record->id_perawatan],
            default => [null, null, null, null],
        };
        if ($menu === null) {
            return;
        }

        $nominal = number_format((float) $record->nominal, 0, ',', '.');
        $judul = "Pengajuan {$record->nomor_pengajuan} {$judulAkhir}";
        $isi = "Pengajuan {$record->nomor_pengajuan} (Rp {$nominal}) {$isiAkhir}";

        if ($record->id_permintaan_pembelian !== null) {
            $idPengaju = $this->repo->idPengajuPermintaanPembelian((string) $record->id_permintaan_pembelian);
            $this->notifikasiService->kirimKePeran(
                \App\Modules\PermintaanPembelian\PermintaanPembelianService::PERAN_PENGADAAN,
                (string) $record->id_perusahaan,
                $judul,
                $isi,
                $tipe,
                $referensiTipe,
                $referensiId,
                $link,
                $idPengaju,
            );
            if ($idPengaju !== null) {
                $this->notifikasiService->buatDanKirim([
                    'id_perusahaan'  => (string) $record->id_perusahaan,
                    'id_pengguna'    => $idPengaju,
                    'judul'          => $judul,
                    'isi'            => $isi,
                    'tipe'           => $tipe,
                    'referensi_id'   => $referensiId,
                    'referensi_tipe' => $referensiTipe,
                    'link'           => $link,
                    'dibaca'         => 0,
                ]);
            }
            return;
        }

        $this->notifikasiService->kirimKePemilikIzinMenu(
            $menu,
            (string) $record->id_perusahaan,
            $judul,
            $isi,
            $tipe,
            $referensiTipe,
            $referensiId,
            $link,
        );
    }

    public function terapkanKeputusanApproval(string $idPengajuan, string $idPerusahaan, string $idPengguna, string $keputusan, ?string $alasanDitolak): void
    {
        $record = $this->repo->findPengajuanById($idPengajuan);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            \Illuminate\Support\Facades\Log::warning("ArusKasApprovalListener: pengajuan {$idPengajuan} tidak ditemukan atau beda perusahaan");
            return;
        }
        if ($record->status !== self::STATUS_MENUNGGU_APPROVAL) {
            return;
        }

        if ($keputusan === 'ditolak') {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DITOLAK,
                'alasan_ditolak' => $alasanDitolak,
            ]);
            $this->catatRiwayat($updated, self::RIWAYAT_DITOLAK, $alasanDitolak, $idPengguna);
            $this->jalankanHookTolak($updated, (string) $alasanDitolak);
            return;
        }

        $updated = $this->repo->updatePengajuan($record, [
            'status'         => self::STATUS_DISETUJUI,
            'disetujui_oleh' => $idPengguna,
            'disetujui_pada' => now(),
        ]);
        $this->jalankanHookSetujui($updated);
    }

    public function terapkanKeputusanPersetujuanTransfer(string $idPengajuan, string $idPerusahaan, string $keputusan, ?string $alasan, string $idPengguna): void
    {
        $record = $this->repo->findPengajuanById($idPengajuan);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            \Illuminate\Support\Facades\Log::warning("ArusKasApprovalListener: pengajuan {$idPengajuan} tidak ditemukan atau beda perusahaan");
            return;
        }
        if ($record->status !== self::STATUS_DICEK) {
            return;
        }

        if ($keputusan === 'ditolak') {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DITOLAK,
                'alasan_ditolak' => $alasan,
            ]);
            $this->catatRiwayat($updated, self::RIWAYAT_DITOLAK, $alasan, $idPengguna);
            $this->jalankanHookTolak($updated, (string) $alasan);
            $this->beritahuPembuatKasbon($updated, 'ditolak', (string) $alasan);
            return;
        }

        $siap = $this->repo->updatePengajuan($record, ['status' => self::STATUS_SIAP_TRANSFER]);
        $this->beritahuKeuanganSiapTransfer($siap);
    }

    private function beritahuKeuangan(PengajuanPengeluaranModel $record, string $tahap, string $awalan = ''): void
    {
        $verifikasi = $tahap === 'verifikasi';
        $antrian = $this->repo->jumlahPengajuanBerstatus(
            (string) $record->id_perusahaan,
            $verifikasi ? self::STATUS_DISETUJUI : self::STATUS_SIAP_TRANSFER,
        );
        $kata = $verifikasi ? 'perlu diverifikasi' : 'siap ditransfer';
        $kataAntrian = $verifikasi ? 'menunggu verifikasi' : 'siap ditransfer';
        $label = self::LABEL_KATEGORI[$record->kategori] ?? ucfirst(str_replace('_', ' ', (string) $record->kategori));
        $rincian = "{$label} Rp " . number_format((float) $record->nominal, 0, ',', '.') . " untuk {$record->penerima}";
        $pelaku = auth()->id();

        $this->notifikasiService->kirimKePeranBeruntun(
            self::PERAN_KEUANGAN,
            (string) $record->id_perusahaan,
            [
                'judul'          => "Pengajuan {$record->nomor_pengajuan} {$kata}",
                'isi'            => $awalan . $rincian . '.' . ($antrian > 1 ? " Total {$antrian} pengajuan {$kataAntrian}." : ''),
                'tipe'           => $verifikasi ? 'keuangan_verifikasi' : 'keuangan_transfer',
                'referensi_tipe' => 'pengajuan_pengeluaran',
                'referensi_id'   => (string) $record->id_pengajuan,
                'link'           => '/proses-pembayaran?tab=' . ($verifikasi ? 'verifikasi' : 'siap'),
            ],
            [
                'judul' => "{$antrian} pengajuan {$kataAntrian}",
                'isi'   => "Terbaru: {$record->nomor_pengajuan} — {$awalan}{$rincian}.",
            ],
            $pelaku !== null ? (string) $pelaku : null,
        );
    }

    private function beritahuKeuanganSiapTransfer(PengajuanPengeluaranModel $record): void
    {
        if ($record->id_permintaan_pembelian !== null && $record->id_termin_pembelian === null) {
            $statusPr = $this->repo->statusPermintaanPembelian((string) $record->id_permintaan_pembelian);
            if (!in_array($statusPr, ['diterima', 'selesai'], true)) {
                return;
            }
        }
        $this->beritahuKeuangan($record, 'transfer');
    }

    public function beritahuKeuanganBarangDiterima(string $idPermintaan, string $awalan): void
    {
        $record = $this->repo->findPengajuanByPermintaanPembelian($idPermintaan);
        if ($record === null || $record->id_termin_pembelian !== null || $record->status !== self::STATUS_SIAP_TRANSFER) {
            return;
        }
        $this->beritahuKeuangan($record, 'transfer', $awalan);
    }

    public function prosesApproval(string $id, string $keputusan, ?string $catatan, string $idPengguna, string $idPerusahaan): array
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);

        $this->pastikanStatus($record, [self::STATUS_MENUNGGU_APPROVAL], 'Pengajuan tidak bisa diproses approval dari status saat ini');

        $kode = $this->kodeEventTypeAktifUntukReferensi($id, $idPerusahaan);

        $this->approvalService->putuskanUntukReferensi(
            $kode,
            $id,
            $idPengguna,
            $keputusan,
            $catatan,
            $idPerusahaan,
        );

        $updated = $this->findPengajuanOrFail($id, $idPerusahaan);
        $pesan = match ($updated->status) {
            self::STATUS_DITOLAK   => 'Pengajuan ditolak',
            self::STATUS_DISETUJUI => 'Pengajuan disetujui',
            default                 => 'Persetujuan Anda tersimpan, menunggu approver lain',
        };

        return ['record' => $updated, 'pesan' => $pesan];
    }

    public function tolak(string $id, string $alasan, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI, self::STATUS_DICEK, self::STATUS_SIAP_TRANSFER], 'Pengajuan tidak bisa ditolak dari status saat ini');

        return DB::transaction(function () use ($id, $idPerusahaan, $alasan) {
            $terkunci = $this->repo->findPengajuanForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Pengajuan pengeluaran tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_DISETUJUI, self::STATUS_DICEK, self::STATUS_SIAP_TRANSFER], 'Pengajuan tidak bisa ditolak dari status saat ini');

            $updated = $this->repo->updatePengajuan($terkunci, [
                'status'         => self::STATUS_DITOLAK,
                'alasan_ditolak' => $alasan,
            ]);
            $penolak = auth()->id();
            $this->catatRiwayat($updated, self::RIWAYAT_DITOLAK, $alasan, $penolak !== null ? (string) $penolak : null);
            $this->jalankanHookTolak($updated, $alasan);
            $this->beritahuPembuatKasbon($updated, 'ditolak', $alasan);
            return $updated;
        });
    }

    private function catatRiwayat(PengajuanPengeluaranModel $record, string $jenis, ?string $keterangan, ?string $oleh, mixed $waktu = null): void
    {
        $keterangan = trim((string) $keterangan);
        $this->repo->insertRiwayatPengajuan([
            'id_pengajuan' => (string) $record->id_pengajuan,
            'jenis'        => $jenis,
            'keterangan'   => $keterangan !== '' ? $keterangan : null,
            'nominal'      => (float) $record->nominal,
            'oleh'         => $oleh,
            'waktu'        => $waktu ?? now(),
        ]);
    }

    private function catatDiajukanUlang(PengajuanPengeluaranModel $record, ?string $catatan = null, ?string $oleh = null): void
    {
        $pelaku = $oleh ?? auth()->id();
        $this->catatRiwayat($record, self::RIWAYAT_DIAJUKAN_ULANG, $catatan, $pelaku !== null ? (string) $pelaku : null);
    }

    private function pastikanPenolakanTercatat(PengajuanPengeluaranModel $record): ?string
    {
        $tercatat = $this->repo->listRiwayatPengajuan((string) $record->id_pengajuan);
        if ($tercatat !== [] && end($tercatat)->jenis === self::RIWAYAT_DITOLAK) {
            $oleh = end($tercatat)->oleh;
            return $oleh !== null ? (string) $oleh : null;
        }

        $oleh = $record->diubah_oleh !== null ? (string) $record->diubah_oleh : null;
        $this->catatRiwayat($record, self::RIWAYAT_DITOLAK, $record->alasan_ditolak, $oleh, $record->diubah_pada);

        return $oleh;
    }

    private function arsipkanJejakPutaran(PengajuanPengeluaranModel $record): void
    {
        foreach ($this->repo->listApproval((string) $record->id_pengajuan) as $baris) {
            if ($baris['waktu_aksi'] === null || !in_array($baris['status_approval'] ?? null, ['menunggu', 'disetujui'], true)) {
                continue;
            }
            $gerbangTransfer = ($baris['kode_event'] ?? null) === self::KODE_PERSETUJUAN_TRANSFER;
            $this->catatRiwayat(
                $record,
                $gerbangTransfer ? $baris['status'] . '_transfer' : $baris['status'],
                $baris['catatan'],
                $baris['id_pengguna'] !== null ? (string) $baris['id_pengguna'] : null,
                $baris['waktu_aksi'],
            );
        }
        if ($record->disetujui_pada !== null) {
            $this->catatRiwayat($record, 'disetujui_final', null, $record->disetujui_oleh !== null ? (string) $record->disetujui_oleh : null, $record->disetujui_pada);
        }
        if ($record->dicek_pada !== null) {
            $this->catatRiwayat($record, self::STATUS_DICEK, null, $record->dicek_oleh !== null ? (string) $record->dicek_oleh : null, $record->dicek_pada);
        }
    }

    private function beritahuPenolak(PengajuanPengeluaranModel $record, ?string $idPenolak, string $idPengaju, string $catatan): void
    {
        if ($idPenolak === null || $idPenolak === $idPengaju) {
            return;
        }

        $nominal = number_format((float) $record->nominal, 0, ',', '.');
        $this->notifikasiService->buatDanKirim([
            'id_perusahaan'  => (string) $record->id_perusahaan,
            'id_pengguna'    => $idPenolak,
            'judul'          => "Pengajuan {$record->nomor_pengajuan} diajukan ulang",
            'isi'            => "Pengajuan {$record->nomor_pengajuan} (Rp {$nominal}) yang Anda tolak diajukan ulang: " . Str::limit($catatan, 200),
            'tipe'           => 'pengajuan_diajukan_ulang',
            'referensi_id'   => (string) $record->id_pengajuan,
            'referensi_tipe' => 'pengajuan_pengeluaran',
            'link'           => '/proses-pembayaran',
            'dibaca'         => 0,
        ]);
    }

    public function ajukanUlangPengajuanPermintaanPembelian(
        string $idPengajuan,
        string $idPermintaan,
        string $idPerusahaan,
        string $idPengguna,
        string $catatan,
        ?float $nominalBaru,
        ?string $versiDilihat = null,
    ): PengajuanPengeluaranModel {
        return DB::transaction(function () use ($idPengajuan, $idPermintaan, $idPerusahaan, $idPengguna, $catatan, $nominalBaru, $versiDilihat) {
            $record = $this->repo->findPengajuanForUpdate($idPengajuan);
            if ($record === null || $record->id_perusahaan !== $idPerusahaan || (string) $record->id_permintaan_pembelian !== $idPermintaan) {
                abort(404, 'Pengajuan pembayaran PR tidak ditemukan');
            }
            if ($record->status !== self::STATUS_DITOLAK) {
                abort(422, 'Pembayaran hanya bisa diajukan ulang saat pengajuannya ditolak');
            }
            if ($versiDilihat !== null && $versiDilihat !== (string) ($record->diubah_pada ?? '')) {
                abort(409, 'Pengajuan pembayaran ini baru saja berubah — muat ulang halaman lalu periksa lagi sebelum mengajukan ulang');
            }

            $idPenolak = $this->pastikanPenolakanTercatat($record);
            $this->arsipkanJejakPutaran($record);

            $this->approvalService->batalkanUntukReferensi(
                [...self::KODE_EVENT_PENGELUARAN, self::KODE_PERSETUJUAN_TRANSFER],
                (string) $record->id_pengajuan,
                $idPerusahaan,
            );

            $bersih = $this->repo->updatePengajuan($record, [
                'nominal'        => $nominalBaru ?? (float) $record->nominal,
                'alasan_ditolak' => null,
                'dicek_oleh'     => null,
                'dicek_pada'     => null,
                'disetujui_oleh' => null,
                'disetujui_pada' => null,
            ]);
            $this->catatDiajukanUlang($bersih, $catatan, $idPengguna);

            $hasil = $this->masukTahapApproval($bersih, $idPengguna);
            $this->beritahuPenolak($hasil, $idPenolak, $idPengguna, $catatan);

            return $hasil;
        });
    }

    public function transfer(string $id, string $tanggalTransfer, ?UploadedFile $bukti, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_SIAP_TRANSFER], 'Pengajuan hanya bisa ditransfer setelah diverifikasi');

        return DB::transaction(function () use ($id, $idPerusahaan, $tanggalTransfer, $bukti) {
            $terkunci = $this->repo->findPengajuanForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Pengajuan pengeluaran tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_SIAP_TRANSFER], 'Pengajuan hanya bisa ditransfer setelah diverifikasi');

            if ($terkunci->id_kasbon !== null) {
                $kasbon = $this->repo->infoKasbonUntukNotifikasi((string) $terkunci->id_kasbon);
                if ($kasbon !== null && (int) ($kasbon->karyawan_aktif ?? 1) !== 1) {
                    abort(409, 'Karyawan penerima kasbon sudah tidak aktif — kasbon tidak bisa dicairkan. Tolak pengajuannya.');
                }
            }

            $statusPembelian = null;
            if ($terkunci->id_pembelian !== null) {
                $statusPembelian = $this->repo->statusPembelian($terkunci->id_pembelian);
                if (!in_array($statusPembelian, ['disetujui_finance', 'dibeli'], true)) {
                    abort(409, 'Pembelian sparepart belum disetujui (status saat ini: ' . ($statusPembelian ?? 'tidak ditemukan') . '), transfer tidak bisa dilakukan');
                }
            }
            if ($terkunci->id_permintaan_pembelian !== null) {
                $statusPr = $this->repo->statusPermintaanPembelian($terkunci->id_permintaan_pembelian);
                if ($terkunci->id_termin_pembelian !== null) {
                    if (!in_array($statusPr, ['dibeli', 'diterima', 'selesai'], true)) {
                        abort(409, 'PR belum ditandai dibeli, transfer termin tidak bisa dilakukan');
                    }
                } elseif ($statusPr === 'diterima_sebagian') {
                    abort(409, 'Barang/jasa pada PR baru diterima sebagian — transfer menunggu penerimaan lengkap atau sisanya ditutup');
                } elseif (!in_array($statusPr, ['diterima', 'selesai'], true)) {
                    abort(409, 'Barang/jasa pada PR belum dikonfirmasi diterima (status saat ini: ' . ($statusPr ?? 'tidak ditemukan') . '), transfer tidak bisa dilakukan');
                }
            }

            $data = [
                'status'           => self::STATUS_DITRANSFER,
                'tanggal_transfer' => $tanggalTransfer,
                'ditransfer_oleh'  => auth()->id(),
                'ditransfer_pada'  => now(),
            ];
            if ($bukti !== null) {
                $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
            }
            $updated = $this->repo->updatePengajuan($terkunci, $data);
            if ($terkunci->id_pembelian !== null) {
                if ($statusPembelian === 'dibeli') {
                    $this->repo->sinkronPembelianLunas($terkunci->id_pembelian, $tanggalTransfer);
                } else {
                    $this->repo->sinkronPembelianUangMuka($terkunci->id_pembelian, $tanggalTransfer);
                }
            }
            if ($terkunci->id_permintaan_pembelian !== null) {
                if ($terkunci->id_termin_pembelian !== null) {
                    $this->repo->tandaiTerminDitransfer((string) $terkunci->id_termin_pembelian, $tanggalTransfer);
                    $this->repo->sinkronPermintaanPembelianSelesaiJikaLunas((string) $terkunci->id_permintaan_pembelian, $tanggalTransfer);
                } else {
                    $this->repo->sinkronPermintaanPembelianSelesai($terkunci->id_permintaan_pembelian, $tanggalTransfer);
                }
            }
            if ($terkunci->id_invoice_vendor !== null) {
                $this->catatPembayaranInvoiceVendor($updated, $tanggalTransfer);
            }
            $this->beritahuTimPelaksana($updated, 'pengadaan_ditransfer',
                'sudah ditransfer',
                "sudah ditransfer oleh Keuangan pada {$tanggalTransfer}.");
            $this->beritahuPembuatKasbon($updated, 'dicairkan', $tanggalTransfer);
            return $updated;
        });
    }

    private function beritahuPembuatKasbon(PengajuanPengeluaranModel $record, string $kejadian, string $rincian): void
    {
        if ($record->id_kasbon === null) {
            return;
        }

        $kasbon = $this->repo->infoKasbonUntukNotifikasi((string) $record->id_kasbon);
        if ($kasbon === null || $kasbon->dibuat_oleh === null || (string) $kasbon->dibuat_oleh === (string) auth()->id()) {
            return;
        }

        $nominal = number_format((float) $record->nominal, 0, ',', '.');
        $nama    = $kasbon->nama_karyawan ?? 'karyawan';
        $rincian = trim($rincian);

        [$judul, $isi] = $kejadian === 'dicairkan'
            ? [
                "Kasbon {$kasbon->nomor_kasbon} sudah dicairkan",
                "Kasbon {$nama} (Rp {$nominal}) sudah ditransfer Keuangan pada " . date('d/m/Y', strtotime($rincian))
                    . '. Cicilan mulai dipotong dari gaji sesuai jadwal.',
            ]
            : [
                "Kasbon {$kasbon->nomor_kasbon} ditolak",
                "Kasbon {$nama} (Rp {$nominal}) ditolak" . ($rincian !== '' ? ': ' . Str::limit($rincian, 200) : '')
                    . '. Perbaiki lalu simpan untuk mengajukan ulang, atau hapus.',
            ];

        $this->notifikasiService->buatDanKirim([
            'id_perusahaan'  => (string) $record->id_perusahaan,
            'id_pengguna'    => (string) $kasbon->dibuat_oleh,
            'judul'          => $judul,
            'isi'            => $isi,
            'tipe'           => 'kasbon_' . $kejadian,
            'referensi_id'   => (string) $record->id_kasbon,
            'referensi_tipe' => 'kasbon',
            'link'           => '/kasbon/' . $record->id_kasbon,
            'dibaca'         => 0,
        ]);
    }

    /**
     * Realisasi transfer pengajuan berkategori pembayaran_vendor: baris
     * pembayaran_vendor dibuat otomatis di sini (bukan dicatat manual dari
     * halaman invoice) supaya arus kas tidak dobel dan invoice langsung
     * ter-update status pembayarannya.
     */
    private function catatPembayaranInvoiceVendor(PengajuanPengeluaranModel $pengajuan, string $tanggalTransfer): void
    {
        $invoice = $this->pembayaranVendorRepo->kunciInvoice((string) $pengajuan->id_invoice_vendor);
        if ($invoice === null) {
            abort(409, 'Invoice vendor tautan pengajuan ini sudah tidak ditemukan — transfer dibatalkan');
        }

        $dibayar = $this->pembayaranVendorRepo->totalDibayar((string) $pengajuan->id_invoice_vendor);
        if (round($dibayar + (float) $pengajuan->nominal, 2) > round((float) $invoice->total, 2)) {
            abort(409, 'Nominal pengajuan melebihi sisa tagihan invoice — periksa pembayaran lain yang sudah tercatat');
        }

        $this->pembayaranVendorRepo->create([
            'id_invoice_vendor' => $pengajuan->id_invoice_vendor,
            'tanggal_bayar'     => $tanggalTransfer,
            'nominal'           => (float) $pengajuan->nominal,
            'metode'            => 'transfer',
            'no_referensi'      => $pengajuan->nomor_pengajuan,
            'url_bukti'         => $pengajuan->url_bukti,
            'catatan'           => $pengajuan->keterangan,
        ]);
        $this->pembayaranVendorRepo->recalcStatusPembayaran((string) $pengajuan->id_invoice_vendor);
    }

    /**
     * Pengajuan pembayaran invoice vendor masuk alur Proses Pembayaran —
     * boleh lebih dari satu per invoice (termin/cicilan); guard totalnya
     * (sudah dibayar + pengajuan yang masih berjalan) tidak melebihi total invoice.
     */
    public function buatPengajuanPembayaranInvoiceVendor(
        string $idInvoiceVendor,
        string $idPerusahaan,
        float $nominal,
        ?string $catatan,
        string $namaVendor,
        string $nomorInvoice,
    ): PengajuanPengeluaranModel {
        return DB::transaction(function () use ($idInvoiceVendor, $idPerusahaan, $nominal, $catatan, $namaVendor, $nomorInvoice) {
            $invoice = $this->pembayaranVendorRepo->kunciInvoice($idInvoiceVendor);
            if ($invoice === null) {
                abort(404, 'Invoice vendor tidak ditemukan');
            }
            if ($invoice->status !== 'diverifikasi') {
                abort(409, 'Invoice belum diverifikasi — pembayaran belum bisa diajukan');
            }

            $dibayar   = $this->pembayaranVendorRepo->totalDibayar($idInvoiceVendor);
            $berjalan  = $this->repo->totalPengajuanBerjalanUntukInvoiceVendor($idInvoiceVendor);
            if (round($dibayar + $berjalan + $nominal, 2) > round((float) $invoice->total, 2)) {
                abort(409, 'Nominal melebihi sisa tagihan (termasuk pengajuan pembayaran lain yang masih berjalan)');
            }

            $record = $this->repo->createPengajuan([
                'id_perusahaan'     => $idPerusahaan,
                'id_invoice_vendor' => $idInvoiceVendor,
                'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
                'kategori'          => 'pembayaran_vendor',
                'nominal'           => $nominal,
                'tanggal_pengajuan' => now()->toDateString(),
                'penerima'          => $namaVendor,
                'keterangan'        => trim("Pembayaran invoice {$nomorInvoice}" . ($catatan !== null && $catatan !== '' ? " — {$catatan}" : '')),
                'status'            => self::STATUS_DIAJUKAN,
            ]);

            return $this->masukTahapApproval($record);
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
        if ($this->repo->pemasukanTertautKasbon($id)) {
            abort(422, 'Pemasukan ini berasal dari pelunasan kasbon — ubah lewat menu Kasbon');
        }
        if ($bukti !== null) {
            $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
        }
        return $this->repo->updatePemasukan($record, $data);
    }

    public function deletePemasukan(string $id, string $idPerusahaan): void
    {
        $record = $this->findPemasukanOrFail($id, $idPerusahaan);
        if ($this->repo->pemasukanTertautKasbon($id)) {
            abort(422, 'Pemasukan ini berasal dari pelunasan kasbon — hapus lewat menu Kasbon');
        }
        $this->repo->deletePemasukan($record);
    }

    public function hapusPemasukanKasbon(string $idPemasukan, string $idPerusahaan): void
    {
        $record = $this->repo->findPemasukanById($idPemasukan);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            return;
        }
        $this->repo->deletePemasukan($record);
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

        DB::transaction(function () use ($idPerusahaan, $perawatan, $totalBiaya, $nopol, $data) {
            $record = $this->repo->createPengajuan([
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
            $this->masukTahapApproval($record);
        });
    }

    public function sinkronNominalPengajuanPerawatan(string $idPerawatan, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $record = $this->repo->findPengajuanByPerawatan($idPerawatan);
        if ($record === null || in_array($record->status, [self::STATUS_DITRANSFER, self::STATUS_DITOLAK], true)) {
            return;
        }

        DB::transaction(function () use ($record, $nominal) {
            $terkunci = $this->repo->findPengajuanForUpdate((string) $record->id_pengajuan);
            if ($terkunci === null || in_array($terkunci->status, [self::STATUS_DITRANSFER, self::STATUS_DITOLAK], true)) {
                return;
            }

            $nominalLama = round((float) $terkunci->nominal, 2);
            $nominalBaru = round($nominal, 2);
            if ($nominalBaru === $nominalLama) {
                return;
            }

            $diperbarui = $this->repo->updatePengajuan($terkunci, ['nominal' => $nominal]);

            if ($nominalBaru < $nominalLama) {
                return;
            }

            if ($diperbarui->status === self::STATUS_MENUNGGU_APPROVAL) {
                $this->resetSnapshotApproval($diperbarui, $nominalLama);
                return;
            }

            if ($diperbarui->status === self::STATUS_DISETUJUI) {
                $batas = $this->batasApproval((string) $diperbarui->id_perusahaan);
                if ($nominal < $batas) {
                    return;
                }
                $dikembalikan = $this->repo->updatePengajuan($diperbarui, [
                    'status'         => self::STATUS_MENUNGGU_APPROVAL,
                    'disetujui_oleh' => null,
                    'disetujui_pada' => null,
                ]);
                $this->resetSnapshotApproval($dikembalikan, $nominalLama);
                return;
            }

            if (in_array($diperbarui->status, [self::STATUS_DICEK, self::STATUS_SIAP_TRANSFER], true)) {
                $batas = $this->batasApproval((string) $diperbarui->id_perusahaan);
                if ($nominal < $batas) {
                    return;
                }
                $dikembalikan = $this->repo->updatePengajuan($diperbarui, [
                    'status'         => self::STATUS_MENUNGGU_APPROVAL,
                    'disetujui_oleh' => null,
                    'disetujui_pada' => null,
                    'dicek_oleh'     => null,
                    'dicek_pada'     => null,
                ]);
                $this->resetSnapshotApproval($dikembalikan, $nominalLama);
            }
        });
    }

    public function pengajuanPerawatanSudahDitransfer(string $idPerawatan): bool
    {
        $record = $this->repo->findPengajuanByPerawatan($idPerawatan);

        return $record !== null && $record->status === self::STATUS_DITRANSFER;
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
        if ($data === null) {
            return;
        }

        $idPerusahaan = (string) $data->id_perusahaan;
        $namaSupplier = $data->nama_supplier !== null && $data->nama_supplier !== '' ? $data->nama_supplier : '-';

        DB::transaction(function () use ($idPerusahaan, $pembelian, $totalEstimasi, $namaSupplier, $data) {
            $record = $this->repo->createPengajuan([
                'id_perusahaan'     => $idPerusahaan,
                'id_pembelian'      => $pembelian->id_pembelian,
                'id_perawatan'      => $data->id_perawatan,
                'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
                'kategori'          => 'sparepart',
                'nominal'           => $totalEstimasi,
                'tanggal_pengajuan' => now()->toDateString(),
                'penerima'          => $namaSupplier,
                'keterangan'        => trim(($data->nomor_ps ?? '') . ($data->nama_supplier !== null && $data->nama_supplier !== '' ? " - {$data->nama_supplier}" : '')),
                'status'            => self::STATUS_DIAJUKAN,
            ]);
            $this->masukTahapApproval($record);
        });
    }

    public function sinkronPerawatanPengajuanPembelian(string $idPembelian, ?string $idPerawatan): void
    {
        $pengajuan = $this->repo->findPengajuanByPembelian($idPembelian);
        if ($pengajuan === null || $pengajuan->id_perawatan === $idPerawatan) {
            return;
        }
        $this->repo->updatePengajuan($pengajuan, ['id_perawatan' => $idPerawatan]);
    }

    public function sinkronNominalPengajuanPembelian(string $idPembelian, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $this->terapkanNominalPengajuan($this->repo->findPengajuanByPembelian($idPembelian), $nominal);
    }

    public function sinkronNominalPengajuanPermintaanPembelian(string $idPermintaan, float $nominal): void
    {
        $record = $this->repo->findPengajuanByPermintaanPembelian($idPermintaan);
        if ($record !== null && $record->status === self::STATUS_DITOLAK) {
            $this->repo->updatePengajuan($record, ['nominal' => $nominal]);
            return;
        }
        $this->terapkanNominalPengajuan($record, $nominal);
    }

    private function terapkanNominalPengajuan(?PengajuanPengeluaranModel $record, float $nominal): void
    {
        if ($record === null || in_array($record->status, [self::STATUS_DITRANSFER, self::STATUS_DITOLAK], true)) {
            return;
        }

        DB::transaction(function () use ($record, $nominal) {
            $terkunci = $this->repo->findPengajuanForUpdate((string) $record->id_pengajuan);
            if ($terkunci === null || in_array($terkunci->status, [self::STATUS_DITRANSFER, self::STATUS_DITOLAK], true)) {
                return;
            }

            $nominalLama = round((float) $terkunci->nominal, 2);
            $nominalBaru = round($nominal, 2);
            if ($nominalBaru === $nominalLama) {
                return;
            }

            $diperbarui = $this->repo->updatePengajuan($terkunci, ['nominal' => $nominal]);

            if ($nominalBaru < $nominalLama) {
                return;
            }

            if ($diperbarui->status === self::STATUS_MENUNGGU_APPROVAL) {
                $this->resetSnapshotApproval($diperbarui, $nominalLama);
                return;
            }

            if ($diperbarui->status === self::STATUS_DISETUJUI) {
                $batas = $this->batasApproval((string) $diperbarui->id_perusahaan);
                if ($nominal < $batas) {
                    return;
                }
                $dikembalikan = $this->repo->updatePengajuan($diperbarui, [
                    'status'         => self::STATUS_MENUNGGU_APPROVAL,
                    'disetujui_oleh' => null,
                    'disetujui_pada' => null,
                ]);
                $this->resetSnapshotApproval($dikembalikan, $nominalLama);
                return;
            }

            if (in_array($diperbarui->status, [self::STATUS_DICEK, self::STATUS_SIAP_TRANSFER], true)) {
                $batas = $this->batasApproval((string) $diperbarui->id_perusahaan);
                if ($nominal < $batas) {
                    return;
                }
                $dikembalikan = $this->repo->updatePengajuan($diperbarui, [
                    'status'         => self::STATUS_MENUNGGU_APPROVAL,
                    'disetujui_oleh' => null,
                    'disetujui_pada' => null,
                    'dicek_oleh'     => null,
                    'dicek_pada'     => null,
                ]);
                $this->resetSnapshotApproval($dikembalikan, $nominalLama);
            }
        });
    }

    public function hapusPengajuanPembelian(string $idPembelian): void
    {
        $record = $this->repo->findPengajuanByPembelian($idPembelian);
        if ($record === null || $record->status === self::STATUS_DITRANSFER) {
            return;
        }

        $this->repo->deletePengajuan($record);
    }

    public function buatPengajuanPermintaanPembelianOtomatis(string $idPermintaan, string $idPerusahaan, string $nomorPermintaan, float $totalAktual, string $namaSupplier, ?string $idPembelian = null, ?string $catatanDibuatUlang = null): void
    {
        if ($totalAktual <= 0) {
            return;
        }
        if ($this->repo->findPengajuanByPermintaanPembelian($idPermintaan) !== null) {
            return;
        }
        DB::transaction(function () use ($idPermintaan, $idPerusahaan, $nomorPermintaan, $totalAktual, $namaSupplier, $idPembelian, $catatanDibuatUlang) {
            $record = $this->repo->createPengajuan([
                'id_perusahaan'           => $idPerusahaan,
                'id_permintaan_pembelian' => $idPermintaan,
                'id_pembelian'            => $idPembelian,
                'nomor_pengajuan'         => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
                'kategori'                => 'pengadaan',
                'nominal'                 => $totalAktual,
                'tanggal_pengajuan'       => now()->toDateString(),
                'penerima'                => $namaSupplier !== '' ? $namaSupplier : '-',
                'keterangan'              => $namaSupplier !== '' ? "{$nomorPermintaan} - {$namaSupplier}" : $nomorPermintaan,
                'status'                  => self::STATUS_DIAJUKAN,
            ]);
            if ($catatanDibuatUlang !== null) {
                $this->catatDiajukanUlang($record, $catatanDibuatUlang);
            }
            $this->masukTahapApproval($record);
        });
    }

    public function buatPengajuanTerminPermintaanPembelian(string $idPermintaan, string $idPerusahaan, string $nomorPr, string $namaSupplier, array $termin): array
    {
        return DB::transaction(function () use ($idPermintaan, $idPerusahaan, $nomorPr, $namaSupplier, $termin) {
            $jumlah = count($termin);
            $peta = [];
            foreach ($termin as $baris) {
                $record = $this->repo->createPengajuan([
                    'id_perusahaan'           => $idPerusahaan,
                    'id_permintaan_pembelian' => $idPermintaan,
                    'id_termin_pembelian'     => (string) $baris['id_termin'],
                    'nomor_pengajuan'         => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
                    'kategori'                => 'pembelian_aset',
                    'nominal'                 => (float) $baris['nominal'],
                    'tanggal_pengajuan'       => now()->toDateString(),
                    'penerima'                => $namaSupplier !== '' ? $namaSupplier : '-',
                    'keterangan'              => "{$nomorPr} · Termin {$baris['urutan']}/{$jumlah}: {$baris['nama']}",
                    'status'                  => self::STATUS_DIAJUKAN,
                ]);
                $this->masukTahapApproval($record);
                $peta[(string) $baris['id_termin']] = (string) $record->id_pengajuan;
            }
            return $peta;
        });
    }

    public function infoPengajuanTerminPembelian(string $idPengajuan): ?array
    {
        $record = $this->repo->findPengajuanById($idPengajuan);
        if ($record === null) {
            return null;
        }
        return $this->susunInfoPengajuan($record);
    }

    public function hapusPengajuanPermintaanPembelian(string $idPermintaan): void
    {
        foreach ($this->repo->listPengajuanByPermintaanPembelian($idPermintaan) as $record) {
            if ($record->status === self::STATUS_DITRANSFER) {
                continue;
            }
            $this->repo->deletePengajuan($record);
        }
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

        DB::transaction(function () use ($idPerusahaan, $periode, $data, $rentang) {
            $record = $this->repo->createPengajuan([
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
            $this->masukTahapApproval($record);
        });
    }

    public function batalkanPengajuanPayroll(string $idPeriode): void
    {
        $record = $this->repo->findPengajuanByPeriode($idPeriode);
        if ($record === null) {
            return;
        }
        $record = $this->repo->findPengajuanForUpdate((string) $record->id_pengajuan);
        if ($record === null) {
            return;
        }
        if ($record->status === self::STATUS_DITRANSFER) {
            abort(409, 'Gaji periode ini sudah ditransfer Keuangan — batalkan tidak diizinkan');
        }
        $this->repo->deletePengajuan($record);
    }

    private function kodeEventTypeAktifUntukReferensi(string $idReferensi, string $idPerusahaan): string
    {
        $kode = DB::table('approval_pengajuan as ap')
            ->join('approval_event_type as et', 'et.id_event_type', '=', 'ap.id_event_type')
            ->where('ap.id_referensi', $idReferensi)
            ->where('ap.id_perusahaan', $idPerusahaan)
            ->where('ap.status', 'menunggu')
            ->whereNull('ap.dihapus_pada')
            ->orderByDesc('ap.dibuat_pada')
            ->value('et.kode');

        return $kode !== null ? (string) $kode : 'pengajuan_pengeluaran';
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

    public function laporanPsak(string $idPerusahaan, ?string $dari, ?string $sampai): array
    {
        $dari   = $dari ?: now()->startOfMonth()->toDateString();
        $sampai = $sampai ?: now()->endOfMonth()->toDateString();

        $this->validasiRentang($dari, $sampai);

        $b = [
            'op_pelanggan' => 0.0, 'op_pengembalian' => 0.0, 'op_masuk_lain' => 0.0,
            'op_uang_jalan' => 0.0, 'op_perawatan' => 0.0, 'op_sparepart' => 0.0,
            'op_legalitas' => 0.0, 'op_gaji' => 0.0, 'op_kasbon' => 0.0, 'op_vendor' => 0.0, 'op_keluar_lain' => 0.0,
            'inv_jual_aset' => 0.0, 'inv_beli_aset' => 0.0,
            'dana_modal' => 0.0, 'dana_bayar_pinjaman' => 0.0,
        ];

        foreach ($this->repo->rekap($idPerusahaan, $dari, $sampai) as $r) {
            $nominal = (float) $r->nominal;
            if ($r->arah === 'masuk') {
                if ($r->sumber === 'faktur') {
                    $b['op_pelanggan'] += $nominal;
                    continue;
                }
                match ($r->kategori) {
                    'pendapatan_jasa'   => $b['op_pelanggan'] += $nominal,
                    'pengembalian_dana' => $b['op_pengembalian'] += $nominal,
                    'penjualan_aset'    => $b['inv_jual_aset'] += $nominal,
                    'modal_pinjaman'    => $b['dana_modal'] += $nominal,
                    default             => $b['op_masuk_lain'] += $nominal,
                };
                continue;
            }
            if ($r->sumber === 'pembayaran_vendor') {
                $b['op_vendor'] += $nominal;
                continue;
            }
            match ($r->kategori) {
                'uang_jalan'          => $b['op_uang_jalan'] += $nominal,
                'perawatan'           => $b['op_perawatan'] += $nominal,
                'sparepart'           => $b['op_sparepart'] += $nominal,
                'legalitas'           => $b['op_legalitas'] += $nominal,
                'penggajian'          => $b['op_gaji'] += $nominal,
                'kasbon'              => $b['op_kasbon'] += $nominal,
                'pembelian_aset'      => $b['inv_beli_aset'] += $nominal,
                'pembayaran_pinjaman' => $b['dana_bayar_pinjaman'] += $nominal,
                default               => $b['op_keluar_lain'] += $nominal,
            };
        }

        $kelompok = [
            [
                'judul'          => 'ARUS KAS DARI AKTIVITAS OPERASI',
                'subtotal_label' => 'Kas Bersih dari Aktivitas Operasi',
                'baris' => [
                    ['label' => 'Penerimaan dari pelanggan',      'arah' => 'masuk',  'nominal' => $b['op_pelanggan']],
                    ['label' => 'Penerimaan pengembalian dana',   'arah' => 'masuk',  'nominal' => $b['op_pengembalian']],
                    ['label' => 'Penerimaan operasional lainnya', 'arah' => 'masuk',  'nominal' => $b['op_masuk_lain']],
                    ['label' => 'Pembayaran uang jalan',          'arah' => 'keluar', 'nominal' => $b['op_uang_jalan']],
                    ['label' => 'Pembayaran perawatan armada',    'arah' => 'keluar', 'nominal' => $b['op_perawatan']],
                    ['label' => 'Pembayaran sparepart',           'arah' => 'keluar', 'nominal' => $b['op_sparepart']],
                    ['label' => 'Pembayaran legalitas',           'arah' => 'keluar', 'nominal' => $b['op_legalitas']],
                    ['label' => 'Pembayaran gaji karyawan',       'arah' => 'keluar', 'nominal' => $b['op_gaji']],
                    ['label' => 'Pemberian kasbon karyawan',      'arah' => 'keluar', 'nominal' => $b['op_kasbon']],
                    ['label' => 'Pembayaran ke vendor',           'arah' => 'keluar', 'nominal' => $b['op_vendor']],
                    ['label' => 'Pembayaran operasional lainnya', 'arah' => 'keluar', 'nominal' => $b['op_keluar_lain']],
                ],
            ],
            [
                'judul'          => 'ARUS KAS DARI AKTIVITAS INVESTASI',
                'subtotal_label' => 'Kas Bersih dari Aktivitas Investasi',
                'baris' => [
                    ['label' => 'Penerimaan penjualan aset', 'arah' => 'masuk',  'nominal' => $b['inv_jual_aset']],
                    ['label' => 'Pembayaran pembelian aset', 'arah' => 'keluar', 'nominal' => $b['inv_beli_aset']],
                ],
            ],
            [
                'judul'          => 'ARUS KAS DARI AKTIVITAS PENDANAAN',
                'subtotal_label' => 'Kas Bersih dari Aktivitas Pendanaan',
                'baris' => [
                    ['label' => 'Penerimaan modal/pinjaman', 'arah' => 'masuk',  'nominal' => $b['dana_modal']],
                    ['label' => 'Pembayaran pinjaman',       'arah' => 'keluar', 'nominal' => $b['dana_bayar_pinjaman']],
                ],
            ],
        ];

        foreach ($kelompok as $i => $k) {
            $kelompok[$i]['subtotal'] = array_reduce(
                $k['baris'],
                fn (float $acc, array $baris) => $acc + ($baris['arah'] === 'masuk' ? $baris['nominal'] : -$baris['nominal']),
                0.0
            );
        }

        $kenaikan  = (float) array_sum(array_column($kelompok, 'subtotal'));
        $saldoAwal = $this->repo->saldoKasSebelum($idPerusahaan, $dari);

        return [
            'kelompok'        => $kelompok,
            'kenaikan_bersih' => $kenaikan,
            'saldo_awal'      => $saldoAwal,
            'saldo_akhir'     => $saldoAwal + $kenaikan,
        ];
    }

    public function namaPerusahaan(string $idPerusahaan): string
    {
        return $this->repo->namaPerusahaan($idPerusahaan) ?? '';
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

    public function buatPengajuanUangJalanManual(
        string $idPerusahaan,
        string $idUangJalan,
        float $nominal,
        string $penerima,
        string $keterangan,
    ): PengajuanPengeluaranModel {
        return $this->buatPengajuanTertaut('id_uang_jalan', $idUangJalan, 'uang_jalan', $idPerusahaan, $nominal, $penerima, $keterangan);
    }

    public function perbaruiPengajuanUangJalan(
        string $idPengajuan,
        string $idPerusahaan,
        float $nominal,
        string $penerima,
        string $keterangan,
    ): PengajuanPengeluaranModel {
        return $this->perbaruiPengajuanTertaut('id_uang_jalan', 'Uang jalan', $idPengajuan, $idPerusahaan, $nominal, $penerima, $keterangan);
    }

    public function hapusPengajuanUangJalan(string $idPengajuan, string $idPerusahaan): void
    {
        $this->hapusPengajuanTertaut('id_uang_jalan', 'Uang jalan', $idPengajuan, $idPerusahaan);
    }

    public function buatPengajuanKasbon(
        string $idPerusahaan,
        string $idKasbon,
        float $nominal,
        string $penerima,
        string $keterangan,
    ): PengajuanPengeluaranModel {
        return $this->buatPengajuanTertaut('id_kasbon', $idKasbon, 'kasbon', $idPerusahaan, $nominal, $penerima, $keterangan);
    }

    public function perbaruiPengajuanKasbon(
        string $idPengajuan,
        string $idPerusahaan,
        float $nominal,
        string $penerima,
        string $keterangan,
    ): PengajuanPengeluaranModel {
        return $this->perbaruiPengajuanTertaut('id_kasbon', 'Kasbon', $idPengajuan, $idPerusahaan, $nominal, $penerima, $keterangan);
    }

    public function hapusPengajuanKasbon(string $idPengajuan, string $idPerusahaan): void
    {
        $this->hapusPengajuanTertaut('id_kasbon', 'Kasbon', $idPengajuan, $idPerusahaan);
    }

    private function buatPengajuanTertaut(
        string $kolom,
        string $idTautan,
        string $kategori,
        string $idPerusahaan,
        float $nominal,
        string $penerima,
        string $keterangan,
    ): PengajuanPengeluaranModel {
        return DB::transaction(function () use ($kolom, $idTautan, $kategori, $idPerusahaan, $nominal, $penerima, $keterangan) {
            $record = $this->repo->createPengajuan([
                'id_perusahaan'     => $idPerusahaan,
                $kolom              => $idTautan,
                'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
                'kategori'          => $kategori,
                'nominal'           => $nominal,
                'tanggal_pengajuan' => now()->toDateString(),
                'penerima'          => $penerima,
                'keterangan'        => $keterangan,
                'status'            => self::STATUS_DIAJUKAN,
            ]);

            return $this->masukTahapApproval($record);
        });
    }

    private function perbaruiPengajuanTertaut(
        string $kolom,
        string $label,
        string $idPengajuan,
        string $idPerusahaan,
        float $nominal,
        string $penerima,
        string $keterangan,
    ): PengajuanPengeluaranModel {
        return DB::transaction(function () use ($kolom, $label, $idPengajuan, $idPerusahaan, $nominal, $penerima, $keterangan) {
            $record = $this->kunciPengajuanTertaut($kolom, $label, $idPengajuan, $idPerusahaan, 'diubah');

            $statusAwal  = $record->status;
            $nominalLama = (float) $record->nominal;
            if ($statusAwal === self::STATUS_DITOLAK) {
                $this->pastikanPenolakanTercatat($record);
            }
            $updated     = $this->repo->updatePengajuan($record, [
                'nominal'    => $nominal,
                'penerima'   => $penerima,
                'keterangan' => $keterangan,
            ]);

            if ($statusAwal === self::STATUS_MENUNGGU_APPROVAL) {
                return $this->resetSnapshotApproval($updated, $nominalLama);
            }

            $this->catatDiajukanUlang($updated);

            $this->approvalService->batalkanUntukReferensi(
                [...self::KODE_EVENT_PENGELUARAN, self::KODE_PERSETUJUAN_TRANSFER],
                (string) $updated->id_pengajuan,
                $idPerusahaan,
            );

            $bersih = $this->repo->updatePengajuan($updated, [
                'alasan_ditolak' => null,
                'dicek_oleh'     => null,
                'dicek_pada'     => null,
                'disetujui_oleh' => null,
                'disetujui_pada' => null,
            ]);

            return $this->masukTahapApproval($bersih);
        });
    }

    private function hapusPengajuanTertaut(string $kolom, string $label, string $idPengajuan, string $idPerusahaan): void
    {
        DB::transaction(function () use ($kolom, $label, $idPengajuan, $idPerusahaan) {
            $record = $this->kunciPengajuanTertaut($kolom, $label, $idPengajuan, $idPerusahaan, 'dihapus');

            $this->approvalService->batalkanUntukReferensi(
                [...self::KODE_EVENT_PENGELUARAN, self::KODE_PERSETUJUAN_TRANSFER],
                (string) $record->id_pengajuan,
                (string) $record->id_perusahaan,
            );

            $this->repo->deletePengajuan($record);
        });
    }

    private function kunciPengajuanTertaut(string $kolom, string $label, string $idPengajuan, string $idPerusahaan, string $aksi): PengajuanPengeluaranModel
    {
        $record = $this->repo->findPengajuanForUpdate($idPengajuan);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan || $record->{$kolom} === null) {
            abort(404, 'Pengajuan ' . mb_strtolower($label) . ' tidak ditemukan');
        }

        $this->pastikanStatus($record, [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DITOLAK], "{$label} hanya bisa {$aksi} saat status menunggu approval atau ditolak");

        return $record;
    }

    /**
     * Status murni pengajuan_pengeluaran untuk pengecekan caller (mis.
     * PenugasanService::update() saat ganti supir) SEBELUM memutuskan boleh
     * tidaknya melepas link id_pengajuan baris lain — sengaja tidak abort 404
     * seperti findPengajuanOrFail(), karena caller hanya perlu tahu status
     * (atau null bila sudah tidak ada), bukan record lengkapnya.
     */
    public function statusPengajuan(string $idPengajuan): ?string
    {
        return $this->repo->findPengajuanById($idPengajuan)?->status;
    }

    /**
     * Dipanggil setelah satu baris penugasan ber-id_pengajuan dihapus.
     * Pengajuan yang sudah disetujui/dicek/ditransfer sengaja dibiarkan beku
     * — nominalnya sudah jadi acuan proses keuangan berjalan. Selama masih
     * menunggu_approval/ditolak (atau diajukan legacy), nominal disinkronkan
     * dan approval aktif di-reset (threshold-aware).
     */
    public function sinkronPengajuanSetelahPenugasanDihapus(string $idPengajuan): void
    {
        $record = $this->repo->findPengajuanById($idPengajuan);
        if ($record === null || in_array($record->status, [self::STATUS_DISETUJUI, self::STATUS_DICEK, self::STATUS_SIAP_TRANSFER, self::STATUS_DITRANSFER], true)) {
            return;
        }

        $hitung = $this->repo->hitungPenugasanTerkaitPengajuan($idPengajuan);
        if ((int) $hitung->jumlah === 0) {
            $this->approvalService->batalkanUntukReferensi(
                [...self::KODE_EVENT_PENGELUARAN, self::KODE_PERSETUJUAN_TRANSFER],
                (string) $record->id_pengajuan,
                (string) $record->id_perusahaan,
            );
            $this->repo->deletePengajuan($record);
            return;
        }

        $nominalLama = (float) $record->nominal;
        $updated = $this->repo->updatePengajuan($record, [
            'nominal'        => (float) $record->tarif_per_hari * (int) $hitung->jumlah,
            'periode_dari'   => $hitung->dari,
            'periode_sampai' => $hitung->sampai,
        ]);

        if (in_array($updated->status, [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DITOLAK], true)) {
            $this->resetSnapshotApproval($updated, $nominalLama);
        }
    }

    public function batasApproval(string $idPerusahaan): float
    {
        $nilai = $this->repo->getPengaturan($idPerusahaan, self::KUNCI_BATAS_APPROVAL);
        return $nilai !== null ? (float) $nilai : 0.0;
    }

    public function setBatasApproval(string $idPerusahaan, float $batas): void
    {
        $this->repo->setPengaturan($idPerusahaan, self::KUNCI_BATAS_APPROVAL, (string) $batas);
    }

    public function wajibApprovalManual(string $idPerusahaan): bool
    {
        return $this->repo->getPengaturan($idPerusahaan, self::KUNCI_WAJIB_APPROVAL_MANUAL) === '1';
    }

    public function setWajibApprovalManual(string $idPerusahaan, bool $wajib): void
    {
        $this->repo->setPengaturan($idPerusahaan, self::KUNCI_WAJIB_APPROVAL_MANUAL, $wajib ? '1' : '0');
    }

    public function batasRealisasiMandiri(string $idPerusahaan): float
    {
        $nilai = $this->repo->getPengaturan($idPerusahaan, self::KUNCI_BATAS_REALISASI_MANDIRI);
        return $nilai !== null ? (float) $nilai : self::DEFAULT_BATAS_REALISASI_MANDIRI;
    }

    public function setBatasRealisasiMandiri(string $idPerusahaan, float $batas): void
    {
        $this->repo->setPengaturan($idPerusahaan, self::KUNCI_BATAS_REALISASI_MANDIRI, (string) $batas);
    }

    public function migrasiApprovalPending(): array
    {
        $records = PengajuanPengeluaranModel::active()
            ->whereIn('status', [self::STATUS_DIAJUKAN, self::STATUS_DICEK])
            ->get();

        $ringkasan = [];
        foreach ($records as $record) {
            $updated = $this->masukTahapApproval($record, $record->dibuat_oleh);
            $ringkasan[$updated->status] = ($ringkasan[$updated->status] ?? 0) + 1;
        }

        return [
            'total'     => $records->count(),
            'ringkasan' => $ringkasan,
        ];
    }
}
