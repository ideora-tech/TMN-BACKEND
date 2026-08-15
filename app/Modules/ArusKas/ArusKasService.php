<?php

declare(strict_types=1);

namespace App\Modules\ArusKas;

use App\Modules\ArusKas\Contracts\ArusKasRepositoryInterface;
use App\Modules\Notifikasi\NotifikasiService;
use App\Modules\Trip\TripModel;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArusKasService
{
    public const STATUS_DIAJUKAN          = 'diajukan';
    public const STATUS_DICEK             = 'dicek';
    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';
    public const STATUS_DISETUJUI         = 'disetujui';
    public const STATUS_DITOLAK           = 'ditolak';
    public const STATUS_DITRANSFER        = 'ditransfer';

    public const KUNCI_BATAS_APPROVAL = 'batas_approval_keuangan';

    public function __construct(
        private readonly ArusKasRepositoryInterface $repo,
        private readonly NotifikasiService $notifikasiService,
    ) {}

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
            '_urutan'    => 0,
        ]];

        if ($record->dicek_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DICEK,
                'waktu'      => $record->dicek_pada,
                'oleh'       => $record->dicek_oleh !== null ? ($namaMap[$record->dicek_oleh] ?? null) : null,
                'keterangan' => null,
                '_urutan'    => 1,
            ];
        }

        foreach ($this->repo->listApproval((string) $record->id_pengajuan) as $baris) {
            if ($baris['waktu_aksi'] === null) {
                continue;
            }
            $riwayat[] = [
                'status'     => $baris['status'],
                'waktu'      => $baris['waktu_aksi'],
                'oleh'       => $baris['nama'],
                'keterangan' => $baris['catatan'],
                '_urutan'    => 2,
            ];
        }

        if ($record->disetujui_pada !== null) {
            $riwayat[] = [
                'status'     => self::STATUS_DISETUJUI,
                'waktu'      => $record->disetujui_pada,
                'oleh'       => $record->disetujui_oleh !== null ? ($namaMap[$record->disetujui_oleh] ?? null) : null,
                'keterangan' => null,
                '_urutan'    => 3,
            ];
        }

        if ($record->status === self::STATUS_DITOLAK) {
            $riwayat[] = [
                'status'     => self::STATUS_DITOLAK,
                'waktu'      => $record->diubah_pada,
                'oleh'       => null,
                'keterangan' => $record->alasan_ditolak,
                '_urutan'    => 3,
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

        return DB::transaction(function () use ($id, $idPerusahaan) {
            $terkunci = $this->repo->findPengajuanForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Pengajuan pengeluaran tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_DIAJUKAN], 'Pengajuan tidak bisa dicek dari status saat ini');

            $dicek = $this->repo->updatePengajuan($terkunci, [
                'status'     => self::STATUS_DICEK,
                'dicek_oleh' => auth()->id(),
                'dicek_pada' => now(),
            ]);
            return $this->masukTahapApproval($dicek);
        });
    }

    private function masukTahapApproval(PengajuanPengeluaranModel $record): PengajuanPengeluaranModel
    {
        $batas = $this->batasApproval((string) $record->id_perusahaan);
        if ((float) $record->nominal < $batas) {
            $updated = $this->repo->updatePengajuan($record, ['status' => self::STATUS_DISETUJUI, 'disetujui_pada' => now()]);
            $this->jalankanHookSetujui($updated);
            return $updated;
        }

        $approvers = $this->repo->resolusiApprover((string) $record->id_perusahaan);
        if ($approvers === []) {
            abort(422, 'Approver keuangan belum dikonfigurasi — atur di Pengaturan → Approval Keuangan');
        }

        $this->repo->insertApprovalRows((string) $record->id_pengajuan, $approvers);
        $updated = $this->repo->updatePengajuan($record, ['status' => self::STATUS_MENUNGGU_APPROVAL]);
        foreach ($approvers as $idPengguna) {
            $this->notifikasiService->buatDanKirim([
                'id_perusahaan' => $record->id_perusahaan,
                'id_pengguna'   => $idPengguna,
                'judul'         => "Pengajuan {$record->nomor_pengajuan} menunggu approval Anda",
                'isi'           => 'Nominal Rp ' . number_format((float) $record->nominal, 0, ',', '.') . " — {$record->penerima} ({$record->kategori})",
                'tipe'          => 'approval_keuangan',
                'referensi_id'   => $record->id_pengajuan,
                'referensi_tipe' => 'pengajuan_pengeluaran',
                'dibaca'         => 0,
            ]);
        }
        return $updated;
    }

    private function resetSnapshotApproval(PengajuanPengeluaranModel $record, float $nominalLama): void
    {
        $this->repo->voidApprovalRows((string) $record->id_pengajuan);

        $approvers = $this->repo->resolusiApprover((string) $record->id_perusahaan);
        if ($approvers === []) {
            abort(422, 'Approver keuangan belum dikonfigurasi — atur di Pengaturan → Approval Keuangan');
        }

        $this->repo->insertApprovalRows((string) $record->id_pengajuan, $approvers);
        foreach ($approvers as $idPengguna) {
            $this->notifikasiService->buatDanKirim([
                'id_perusahaan'  => $record->id_perusahaan,
                'id_pengguna'    => $idPengguna,
                'judul'          => "Pengajuan {$record->nomor_pengajuan} perlu approval ulang",
                'isi'            => 'Nominal berubah dari Rp ' . number_format($nominalLama, 0, ',', '.') . ' menjadi Rp ' . number_format((float) $record->nominal, 0, ',', '.'),
                'tipe'           => 'approval_keuangan',
                'referensi_id'   => $record->id_pengajuan,
                'referensi_tipe' => 'pengajuan_pengeluaran',
                'dibaca'         => 0,
            ]);
        }
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

    public function prosesApproval(string $id, string $keputusan, ?string $catatan, string $idPengguna, string $idPerusahaan): array
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);

        if ($record->status === self::STATUS_DICEK) {
            $record = DB::transaction(function () use ($id, $idPerusahaan) {
                $terkunci = $this->repo->findPengajuanForUpdate($id);
                if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                    abort(404, 'Pengajuan pengeluaran tidak ditemukan');
                }
                if ($terkunci->status !== self::STATUS_DICEK) {
                    return $terkunci;
                }
                return $this->masukTahapApproval($terkunci);
            });
            if ($record->status === self::STATUS_DISETUJUI) {
                return [
                    'record' => $record,
                    'pesan'  => 'Pengajuan ini otomatis disetujui (nominal di bawah batas approval) — keputusan Anda tidak diperlukan',
                ];
            }
        }

        $this->pastikanStatus($record, [self::STATUS_MENUNGGU_APPROVAL], 'Pengajuan tidak bisa diproses approval dari status saat ini');

        $idPengajuan = (string) $record->id_pengajuan;
        $barisMenunggu = $this->repo->findApprovalMenunggu($idPengajuan, $idPengguna);
        if ($barisMenunggu === null) {
            $sudahBerpartisipasi = collect($this->repo->listApproval($idPengajuan))
                ->contains(fn (array $baris) => $baris['id_pengguna'] === $idPengguna);
            if ($sudahBerpartisipasi) {
                abort(409, 'Anda sudah memberikan keputusan');
            }
            abort(403, 'Anda bukan approver pengajuan ini');
        }

        return DB::transaction(function () use ($id, $idPerusahaan, $keputusan, $catatan, $idPengguna, $barisMenunggu, $idPengajuan) {
            $terkunci = $this->repo->findPengajuanForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Pengajuan pengeluaran tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_MENUNGGU_APPROVAL], 'Pengajuan tidak bisa diproses approval dari status saat ini');

            if ($keputusan === 'tolak') {
                $terupdate = $this->repo->updateApprovalRowJikaMenunggu((string) $barisMenunggu->id_approval, [
                    'status'     => 'ditolak',
                    'catatan'    => $catatan,
                    'waktu_aksi' => now(),
                ]);
                if ($terupdate === 0) {
                    abort(409, 'Keputusan sudah diproses');
                }
                $updated = $this->repo->updatePengajuan($terkunci, [
                    'status'         => self::STATUS_DITOLAK,
                    'alasan_ditolak' => $catatan,
                ]);
                $this->jalankanHookTolak($updated, (string) $catatan);
                return ['record' => $updated, 'pesan' => 'Pengajuan ditolak'];
            }

            $terupdate = $this->repo->updateApprovalRowJikaMenunggu((string) $barisMenunggu->id_approval, [
                'status'     => 'disetujui',
                'waktu_aksi' => now(),
            ]);
            if ($terupdate === 0) {
                abort(409, 'Keputusan sudah diproses');
            }

            $sisaMenunggu = $this->repo->hitungApprovalMenunggu($idPengajuan);

            if ($sisaMenunggu > 0) {
                return ['record' => $terkunci, 'pesan' => 'Persetujuan Anda tersimpan, menunggu approver lain'];
            }

            $updated = $this->repo->updatePengajuan($terkunci, [
                'status'         => self::STATUS_DISETUJUI,
                'disetujui_oleh' => $idPengguna,
                'disetujui_pada' => now(),
            ]);
            $this->jalankanHookSetujui($updated);
            return ['record' => $updated, 'pesan' => 'Pengajuan disetujui'];
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
            $this->jalankanHookTolak($updated, $alasan);
            return $updated;
        });
    }

    public function transfer(string $id, string $tanggalTransfer, ?UploadedFile $bukti, string $idPerusahaan): PengajuanPengeluaranModel
    {
        $record = $this->findPengajuanOrFail($id, $idPerusahaan);
        $this->pastikanStatus($record, [self::STATUS_DISETUJUI], 'Pengajuan hanya bisa ditransfer setelah disetujui');

        return DB::transaction(function () use ($id, $idPerusahaan, $tanggalTransfer, $bukti) {
            $terkunci = $this->repo->findPengajuanForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Pengajuan pengeluaran tidak ditemukan');
            }
            $this->pastikanStatus($terkunci, [self::STATUS_DISETUJUI], 'Pengajuan hanya bisa ditransfer setelah disetujui');

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

    public function listApprover(string $idPerusahaan): array
    {
        return $this->repo->listApprover($idPerusahaan);
    }

    public function tambahApprover(array $data, string $idPerusahaan): void
    {
        $idRef = $data['tipe'] === 'jabatan' ? ($data['id_jabatan'] ?? null) : ($data['id_pengguna'] ?? null);

        if ($data['tipe'] === 'jabatan') {
            if (!$this->repo->jabatanMilik((string) $idRef, $idPerusahaan)) {
                abort(404, 'Jabatan tidak ditemukan');
            }
        } else {
            if (!$this->repo->penggunaMilik((string) $idRef, $idPerusahaan)) {
                abort(404, 'Pengguna tidak ditemukan');
            }
        }

        if ($this->repo->adaApproverAktif($idPerusahaan, $data['tipe'], $idRef)) {
            abort(409, 'Approver sudah terdaftar');
        }

        $this->repo->insertApprover([
            'id_perusahaan' => $idPerusahaan,
            'tipe'          => $data['tipe'],
            'id_jabatan'    => $data['id_jabatan'] ?? null,
            'id_pengguna'   => $data['id_pengguna'] ?? null,
            'aktif'         => 1,
        ]);
    }

    public function hapusApprover(string $id, string $idPerusahaan): void
    {
        if (!$this->repo->softDeleteApprover($id, $idPerusahaan)) {
            abort(404, 'Approver tidak ditemukan');
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
}
