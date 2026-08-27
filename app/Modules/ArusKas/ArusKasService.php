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

    public const KODE_PERSETUJUAN_TRANSFER = 'persetujuan_transfer';

    public const KODE_EVENT_PENGELUARAN = [
        'pengajuan_pengeluaran',
        'uang_jalan',
        'legalitas',
        'perawatan',
        'sparepart',
        'penggajian',
        'pembelian_aset',
        'pembayaran_pinjaman',
        'lainnya',
    ];

    public function __construct(
        private readonly ArusKasRepositoryInterface $repo,
        private readonly NotifikasiService $notifikasiService,
        private readonly \App\Modules\Approval\ApprovalService $approvalService,
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

        foreach ($this->repo->listApproval((string) $record->id_pengajuan) as $baris) {
            if ($baris['waktu_aksi'] === null) {
                continue;
            }
            $riwayat[] = [
                'status'     => $baris['status'],
                'waktu'      => $baris['waktu_aksi'],
                'oleh'       => $baris['nama'],
                'keterangan' => $baris['catatan'],
                '_urutan'    => 1,
            ];
        }

        if ($record->disetujui_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DISETUJUI,
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

        if ($record->status === self::STATUS_DITOLAK) {
            $riwayat[] = [
                'status'     => self::STATUS_DITOLAK,
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

        return [
            'id_pengajuan'    => $record->id_pengajuan,
            'nomor_pengajuan' => $record->nomor_pengajuan,
            'status'          => $record->status,
            'nominal'         => (float) $record->nominal,
            'riwayat'         => $riwayat,
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
        $approval = array_map(fn (array $baris) => [
            'id_pengguna' => $baris['id_pengguna'],
            'nama'        => $baris['nama'],
            'status'      => $baris['status'],
            'catatan'     => $baris['catatan'],
            'waktu_aksi'  => $baris['waktu_aksi'],
        ], $approvalMentah);

        $record->approval          = $approval;
        $record->approval_progress = $approval === [] ? null : [
            'disetujui' => count(array_filter($approval, fn (array $baris) => $baris['status'] === 'disetujui')),
            'total'     => count($approval),
        ];
        $record->bisa_approve = $record->status === self::STATUS_MENUNGGU_APPROVAL
            && collect($approval)->contains(fn (array $baris) => $baris['id_pengguna'] === $idPenggunaLogin && $baris['status'] === 'menunggu');
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
            $record = $this->repo->createPengajuan($data);
            return $this->masukTahapApproval($record);
        });
    }

    public function updatePengajuan(string $id, array $data, string $idPerusahaan, ?UploadedFile $bukti): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_MENUNGGU_APPROVAL, self::STATUS_DITOLAK], 'Pengajuan hanya bisa diubah saat status menunggu approval atau ditolak');
        if ($bukti !== null) {
            $data['url_bukti'] = PenyimpananBerkas::simpan($bukti, 'bukti-kas');
        }

        return DB::transaction(function () use ($record, $data) {
            $statusAwal  = $record->status;
            $nominalLama = (float) $record->nominal;
            $updated     = $this->repo->updatePengajuan($record, $data);

            if ($statusAwal === self::STATUS_MENUNGGU_APPROVAL) {
                return $this->resetSnapshotApproval($updated, $nominalLama);
            }

            return $this->masukTahapApproval($updated);
        });
    }

    public function deletePengajuan(string $id, string $idPerusahaan): void
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
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

            return $this->repo->updatePengajuan($diperbarui, ['status' => self::STATUS_SIAP_TRANSFER]);
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

        $kode = $this->approvalService->adaEventTypeAktif((string) $record->kategori, (string) $record->id_perusahaan)
            ? (string) $record->kategori
            : 'pengajuan_pengeluaran';

        $this->approvalService->ajukan(
            $kode,
            (string) $record->id_pengajuan,
            (string) $record->dibuat_oleh,
            (float) $record->nominal,
            (string) $record->id_perusahaan,
        );

        return $this->repo->updatePengajuan($record, ['status' => self::STATUS_MENUNGGU_APPROVAL]);
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

        $kode = $this->approvalService->adaEventTypeAktif((string) $record->kategori, (string) $record->id_perusahaan)
            ? (string) $record->kategori
            : 'pengajuan_pengeluaran';

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
                'isi'            => sprintf(
                    'Nominal pengajuan %s berubah dari Rp %s menjadi Rp %s — perlu approval ulang',
                    $record->nomor_pengajuan,
                    number_format($nominalLama, 0, ',', '.'),
                    number_format((float) $record->nominal, 0, ',', '.'),
                ),
                'tipe'           => 'approval_keuangan',
                'referensi_id'   => (string) $record->id_pengajuan,
                'referensi_tipe' => 'pengajuan_pengeluaran',
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
    }

    private function jalankanHookTolak(PengajuanPengeluaranModel $record, string $alasan): void
    {
        if ($record->id_pembelian !== null) {
            $this->repo->sinkronPembelianTolak($record->id_pembelian, $alasan);
        }
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
            $this->jalankanHookTolak($updated, (string) $alasan);
            return;
        }

        $this->repo->updatePengajuan($record, ['status' => self::STATUS_SIAP_TRANSFER]);
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

        return DB::transaction(function () use ($record, $alasan) {
            $updated = $this->repo->updatePengajuan($record, [
                'status'         => self::STATUS_DITOLAK,
                'alasan_ditolak' => $alasan,
            ]);
            $this->jalankanHookTolak($updated, $alasan);
            return $updated;
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

            $statusPembelian = null;
            if ($terkunci->id_pembelian !== null) {
                $statusPembelian = $this->repo->statusPembelian($terkunci->id_pembelian);
                if (!in_array($statusPembelian, ['disetujui_finance', 'dibeli'], true)) {
                    abort(409, 'Pembelian sparepart belum disetujui finance (status saat ini: ' . ($statusPembelian ?? 'tidak ditemukan') . '), transfer tidak bisa dilakukan');
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

        DB::transaction(function () use ($idPerusahaan, $pembelian, $totalEstimasi, $namaSupplier, $data) {
            $record = $this->repo->createPengajuan([
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
            $this->masukTahapApproval($record);
        });
    }

    public function sinkronNominalPengajuanPembelian(string $idPembelian, float|null $nominal): void
    {
        if ($nominal === null) {
            return;
        }

        $record = $this->repo->findPengajuanByPembelian($idPembelian);
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
            'op_legalitas' => 0.0, 'op_gaji' => 0.0, 'op_vendor' => 0.0, 'op_keluar_lain' => 0.0,
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

    /**
     * Satu pengajuan uang jalan untuk seluruh tanggal sukses dalam satu batch
     * assign penugasan harian (bukan per-tanggal) — nominal = tarif × jumlah
     * tanggal, periode = rentang MIN..MAX tanggal sukses.
     */
    public function buatPengajuanUangJalanPenugasan(
        string $idPerusahaan,
        string $idSupir,
        string $idProyek,
        float $tarif,
        array $tanggalList,
    ): PengajuanPengeluaranModel {
        sort($tanggalList);
        $jumlah = count($tanggalList);
        $dari   = $tanggalList[0];
        $sampai = $tanggalList[$jumlah - 1];

        $info = $this->repo->dataUntukPengajuanPenugasan($idSupir, $idProyek);

        return DB::transaction(function () use ($idPerusahaan, $idSupir, $idProyek, $dari, $sampai, $tarif, $jumlah, $info) {
            $record = $this->repo->createPengajuan([
                'id_perusahaan'     => $idPerusahaan,
                'id_supir'          => $idSupir,
                'id_proyek'         => $idProyek,
                'periode_dari'      => $dari,
                'periode_sampai'    => $sampai,
                'tarif_per_hari'    => $tarif,
                'nomor_pengajuan'   => $this->repo->nomorPengajuanBerikutnya($idPerusahaan),
                'kategori'          => 'uang_jalan',
                'nominal'           => $tarif * $jumlah,
                'tanggal_pengajuan' => now()->toDateString(),
                'penerima'          => $info->nama_supir,
                'keterangan'        => sprintf(
                    'Uang jalan %s — %s (%s–%s, Rp %s/hari × %d hari)',
                    $info->nama_supir,
                    $info->nama_proyek,
                    Carbon::parse($dari)->format('d/m'),
                    Carbon::parse($sampai)->format('d/m'),
                    number_format($tarif, 0, ',', '.'),
                    $jumlah,
                ),
                'status' => self::STATUS_DIAJUKAN,
            ]);
            return $this->masukTahapApproval($record);
        });
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
