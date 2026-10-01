<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor;

use App\Modules\Notifikasi\NotifikasiService;
use App\Modules\PermintaanVendor\Contracts\PermintaanVendorRepositoryInterface;
use App\Support\KodeOtomatis;
use Illuminate\Support\Facades\DB;

class PermintaanVendorService
{
    private const MENU_TIM_VENDOR = ['/kontrak-vendor', '/permintaan-vendor'];

    public function __construct(
        private readonly PermintaanVendorRepositoryInterface $repo,
        private readonly NotifikasiService $notifikasiService,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null, ?string $idPenawaran = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status, $idPenawaran);

        return [
            'data' => $result->items(),
            'meta' => [
                'page'       => $result->currentPage(),
                'limit'      => $result->perPage(),
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
                'ringkasan'  => $this->repo->ringkasanStatus($idPerusahaan),
                'kpi'        => $this->repo->ringkasanKpi($idPerusahaan),
            ],
        ];
    }

    public function jumlahAktif(string $idPerusahaan): array
    {
        $ringkasan = $this->repo->ringkasanStatus($idPerusahaan);
        $disetujui = (int) ($ringkasan['disetujui'] ?? 0);
        $diproses  = (int) ($ringkasan['diproses'] ?? 0);

        return [
            'jumlah'    => $disetujui + $diproses,
            'disetujui' => $disetujui,
            'diproses'  => $diproses,
        ];
    }

    public function findOrFail(string $id, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->repo->findAktifMilikPerusahaan($id, $idPerusahaan);
        if ($record === null) {
            abort(404, 'Permintaan vendor tidak ditemukan');
        }
        return $record;
    }

    public function create(array $data): PermintaanVendorModel
    {
        $idPerusahaan = (string) $data['id_perusahaan'];

        $this->validasiReferensi($data, $idPerusahaan);

        $unitRows = $this->normalisasiUnit($data['unit'] ?? null, $data['id_jenis_kendaraan'] ?? null, $data['jumlah_unit'] ?? null);
        $this->validasiUnit($unitRows, $idPerusahaan);

        unset($data['unit']);
        $data['nomor_permintaan']   = KodeOtomatis::berikutnya($idPerusahaan, 'permintaan_vendor');
        $data['jumlah_unit']        = array_sum(array_column($unitRows, 'jumlah_unit'));
        $data['id_jenis_kendaraan'] = $unitRows[0]['id_jenis_kendaraan'];
        $data['status']             = 'draft';

        return DB::transaction(fn () => $this->repo->create($data, $unitRows));
    }

    public function update(string $id, array $data, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['draft', 'ditolak'], true)) {
            abort(422, 'Hanya permintaan berstatus draft atau ditolak yang dapat diubah');
        }

        $this->validasiReferensi($data, $idPerusahaan, $record);

        $unitDikirim = array_key_exists('unit', $data)
            || array_key_exists('id_jenis_kendaraan', $data)
            || array_key_exists('jumlah_unit', $data);

        $unitRows = null;
        if ($unitDikirim) {
            $idJenisUntukUnit = array_key_exists('id_jenis_kendaraan', $data) ? $data['id_jenis_kendaraan'] : $record->id_jenis_kendaraan;
            $jumlahUntukUnit  = array_key_exists('jumlah_unit', $data) ? $data['jumlah_unit'] : $record->jumlah_unit;

            $unitRows = $this->normalisasiUnit($data['unit'] ?? null, $idJenisUntukUnit, $jumlahUntukUnit);
            $this->validasiUnit($unitRows, $idPerusahaan);

            unset($data['unit']);
            $data['jumlah_unit']        = array_sum(array_column($unitRows, 'jumlah_unit'));
            $data['id_jenis_kendaraan'] = $unitRows[0]['id_jenis_kendaraan'];
        }

        if ($record->status === 'ditolak') {
            $cek = clone $record;
            $cek->fill($data);
            if ($cek->isDirty()) {
                $data['status'] = 'draft';
                $data['alasan_ditolak'] = null;
            }
        }

        return DB::transaction(fn () => $this->repo->update($record, $data, $unitRows));
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['draft', 'ditolak'], true)) {
            abort(422, 'Hanya permintaan berstatus draft atau ditolak yang dapat dihapus');
        }

        $this->repo->delete($record);
    }

    public function ajukanApproval(string $id, string $idPengguna, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($record->status !== 'draft') {
            abort(422, 'Hanya permintaan berstatus draft yang bisa diajukan approval');
        }
        $this->pastikanPenawaranMasihBerlaku($record);

        return DB::transaction(function () use ($id, $idPengguna, $idPerusahaan) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Permintaan vendor tidak ditemukan');
            }
            if ($terkunci->status !== 'draft') {
                abort(422, 'Hanya permintaan berstatus draft yang bisa diajukan approval');
            }

            $approvalService = app(\App\Modules\Approval\ApprovalService::class);

            if (!$approvalService->eventTypeAktifAda('permintaan_vendor', $idPerusahaan)) {
                $disetujui = $this->repo->update($terkunci, [
                    'status'         => 'disetujui',
                    'alasan_ditolak' => null,
                    'disetujui_pada' => now(),
                ]);
                $this->beritahuTimVendor($disetujui, 'disetujui', $idPengguna);
                return $disetujui;
            }

            $approvalService->ajukan(
                'permintaan_vendor',
                $id,
                $idPengguna,
                null,
                $idPerusahaan,
            );

            $diajukan = $this->repo->update($terkunci, [
                'status'         => 'menunggu_approval',
                'alasan_ditolak' => null,
            ]);
            $this->beritahuTimVendor($diajukan, 'menunggu_approval', $idPengguna);
            return $diajukan;
        });
    }

    public function proses(string $id, string $idPengguna, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $pesanStatus = 'Hanya permintaan berstatus disetujui yang bisa diproses';

        if ($record->status !== 'disetujui') {
            abort(422, $pesanStatus);
        }

        return DB::transaction(function () use ($id, $idPengguna, $idPerusahaan, $pesanStatus) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || (string) $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Permintaan vendor tidak ditemukan');
            }
            if ($terkunci->status !== 'disetujui') {
                abort(422, $pesanStatus);
            }

            $diproses = $this->repo->update($terkunci, [
                'status'        => 'diproses',
                'diproses_oleh' => $idPengguna,
                'diproses_pada' => now(),
            ]);

            $this->beritahuPengaju(
                $diproses,
                "Permintaan vendor {$diproses->nomor_permintaan} sedang diproses Pengadaan",
                $this->ringkasanUnit($diproses),
                $idPengguna,
            );

            return $diproses;
        });
    }

    public function tolak(string $id, string $alasan, string $idPengguna, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $bolehStatus = ['disetujui', 'diproses'];
        $pesanStatus = 'Hanya permintaan berstatus disetujui atau diproses yang bisa ditolak Pengadaan';

        if (!in_array($record->status, $bolehStatus, true)) {
            abort(422, $pesanStatus);
        }

        return DB::transaction(function () use ($id, $alasan, $idPengguna, $idPerusahaan, $bolehStatus, $pesanStatus) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || (string) $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Permintaan vendor tidak ditemukan');
            }
            if (!in_array($terkunci->status, $bolehStatus, true)) {
                abort(422, $pesanStatus);
            }

            $ditolak = $this->repo->update($terkunci, [
                'status'                 => 'ditolak_pengadaan',
                'alasan_tolak_pengadaan' => $alasan,
                'ditolak_pengadaan_oleh' => $idPengguna,
                'ditolak_pengadaan_pada' => now(),
            ]);

            $this->beritahuPengaju(
                $ditolak,
                "Permintaan vendor {$ditolak->nomor_permintaan} ditolak Pengadaan",
                $alasan,
                $idPengguna,
            );

            return $ditolak;
        });
    }

    public function revisi(string $id, string $idPengguna, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $pesanStatus = 'Hanya permintaan berstatus disetujui yang bisa direvisi — bila sudah diproses Pengadaan, batalkan lalu buat permintaan baru';

        if ($record->status !== 'disetujui') {
            abort(422, $pesanStatus);
        }

        return DB::transaction(function () use ($id, $idPengguna, $idPerusahaan, $pesanStatus) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || (string) $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Permintaan vendor tidak ditemukan');
            }
            if ($terkunci->status !== 'disetujui' || $terkunci->id_kontrak_vendor !== null) {
                abort(422, $pesanStatus);
            }

            $direvisi = $this->repo->update($terkunci, [
                'status'         => 'draft',
                'alasan_ditolak' => null,
                'disetujui_pada' => null,
            ]);

            $this->notifikasiService->kirimKePemilikIzinMenu(
                self::MENU_TIM_VENDOR,
                (string) $direvisi->id_perusahaan,
                "Permintaan vendor {$direvisi->nomor_permintaan} ditarik untuk direvisi",
                'Tunda proses — permintaan akan diajukan ulang setelah direvisi Sales',
                'permintaan_vendor',
                'permintaan_vendor',
                (string) $direvisi->id_permintaan,
                '/permintaan-vendor/' . $direvisi->id_permintaan,
                $idPengguna,
            );

            return $direvisi;
        });
    }

    public function batal(string $id, string $alasan, string $idPengguna, string $idPerusahaan): PermintaanVendorModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $bolehStatus = ['draft', 'menunggu_approval', 'disetujui', 'diproses'];
        $pesanStatus = 'Permintaan tidak bisa dibatalkan pada status ini';

        if (!in_array($record->status, $bolehStatus, true)) {
            abort(422, $pesanStatus);
        }

        return DB::transaction(function () use ($id, $alasan, $idPengguna, $idPerusahaan, $bolehStatus, $pesanStatus) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || (string) $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Permintaan vendor tidak ditemukan');
            }
            if (!in_array($terkunci->status, $bolehStatus, true)) {
                abort(422, $pesanStatus);
            }

            if ($terkunci->status === 'menunggu_approval') {
                app(\App\Modules\Approval\ApprovalService::class)
                    ->batalkanUntukReferensi(['permintaan_vendor'], $id, $idPerusahaan);
            }

            $dibatalkan = $this->repo->update($terkunci, [
                'status'       => 'dibatalkan',
                'alasan_batal' => $alasan,
            ]);

            $this->notifikasiService->kirimKePemilikIzinMenu(
                ['/kontrak-vendor'],
                (string) $dibatalkan->id_perusahaan,
                "Permintaan vendor {$dibatalkan->nomor_permintaan} dibatalkan",
                $alasan,
                'permintaan_vendor',
                'permintaan_vendor',
                (string) $dibatalkan->id_permintaan,
                '/permintaan-vendor/' . $dibatalkan->id_permintaan,
                $idPengguna,
            );

            return $dibatalkan;
        });
    }

    public function beritahuPengaju(PermintaanVendorModel $record, string $judul, string $isi, ?string $kecualiIdPengguna = null): void
    {
        $idPengaju = $record->dibuat_oleh !== null ? (string) $record->dibuat_oleh : null;
        if ($idPengaju === null || $idPengaju === '' || $idPengaju === $kecualiIdPengguna) {
            return;
        }

        $this->notifikasiService->buatDanKirim([
            'id_perusahaan'  => (string) $record->id_perusahaan,
            'id_pengguna'    => $idPengaju,
            'judul'          => $judul,
            'isi'            => $isi,
            'tipe'           => 'permintaan_vendor',
            'referensi_id'   => (string) $record->id_permintaan,
            'referensi_tipe' => 'permintaan_vendor',
            'link'           => '/permintaan-vendor/' . $record->id_permintaan,
            'dibaca'         => 0,
        ]);
    }

    public function ringkasanUnit(PermintaanVendorModel $record): string
    {
        $unit = collect($record->unit_diminta ?? [])
            ->map(fn ($u) => ((string) ($u['nama_jenis_kendaraan'] ?? 'Unit')) . ' x ' . ((int) ($u['jumlah_unit'] ?? 0)))
            ->implode(', ');

        $rincian = implode(' - ', array_values(array_filter([
            $record->nama_proyek !== null ? "Proyek {$record->nama_proyek}" : null,
            $unit !== '' ? $unit : null,
        ])));

        return $rincian !== '' ? $rincian : "Permintaan {$record->nomor_permintaan}";
    }

    private function beritahuTimVendor(PermintaanVendorModel $record, string $tahap, ?string $kecualiIdPengguna = null): void
    {
        $unit = collect($record->unit_diminta ?? [])
            ->map(fn ($u) => ((string) ($u['nama_jenis_kendaraan'] ?? 'Unit')) . ' x ' . ((int) ($u['jumlah_unit'] ?? 0)))
            ->implode(', ');
        $menunggu = $tahap === 'menunggu_approval';
        $rincian = array_values(array_filter([
            $record->nama_proyek !== null ? "Proyek {$record->nama_proyek}" : null,
            $unit !== '' ? $unit : null,
            $menunggu ? 'Diajukan oleh tim sales' : 'Sudah disetujui, silakan proses kontrak vendor',
        ]));

        $this->notifikasiService->kirimKePemilikIzinMenu(
            self::MENU_TIM_VENDOR,
            (string) $record->id_perusahaan,
            $menunggu
                ? "Permintaan vendor {$record->nomor_permintaan} menunggu approval"
                : "Permintaan vendor {$record->nomor_permintaan} siap dikontrakkan",
            implode(' - ', $rincian),
            'permintaan_vendor',
            'permintaan_vendor',
            (string) $record->id_permintaan,
            '/permintaan-vendor/' . $record->id_permintaan,
            $kecualiIdPengguna,
        );
    }

    public function terapkanKeputusanApproval(string $idPermintaan, string $idPerusahaan, string $keputusan, ?string $alasanDitolak): void
    {
        $record = $this->repo->findAktifMilikPerusahaan($idPermintaan, $idPerusahaan);
        if ($record === null) {
            \Illuminate\Support\Facades\Log::warning("PermintaanVendorApprovalListener: permintaan {$idPermintaan} tidak ditemukan atau beda perusahaan");
            return;
        }
        if ($record->status !== 'menunggu_approval') {
            return;
        }

        if ($keputusan === 'ditolak') {
            $this->repo->update($record, [
                'status'         => 'ditolak',
                'alasan_ditolak' => $alasanDitolak,
            ]);
            return;
        }

        $disetujui = $this->repo->update($record, ['status' => 'disetujui', 'disetujui_pada' => now()]);
        $this->beritahuTimVendor($disetujui, 'disetujui');
    }

    private function validasiReferensi(array &$data, string $idPerusahaan, ?PermintaanVendorModel $record = null): void
    {
        $idPenawaranLama = $record?->id_penawaran !== null ? (string) $record->id_penawaran : null;
        $idPenawaran = array_key_exists('id_penawaran', $data)
            ? (!empty($data['id_penawaran']) ? (string) $data['id_penawaran'] : null)
            : $idPenawaranLama;
        $penawaranBaru = $idPenawaran !== null && $idPenawaran !== $idPenawaranLama;

        if ($idPenawaran !== null) {
            $penawaran = $this->repo->penawaranMilikPerusahaan($idPenawaran, $idPerusahaan);
            if ($penawaran === null && $penawaranBaru) {
                abort(404, 'Penawaran tidak ditemukan');
            }
            if ($penawaran !== null && $penawaranBaru && $penawaran->status === 'ditolak') {
                abort(422, 'Penawaran sudah ditolak — tidak bisa mengajukan permintaan vendor');
            }

            if ($penawaran !== null && $penawaran->id_proyek !== null) {
                $idProyekDiminta = array_key_exists('id_proyek', $data) ? $data['id_proyek'] : $record?->id_proyek;
                if (!empty($idProyekDiminta) && (string) $idProyekDiminta !== (string) $penawaran->id_proyek) {
                    abort(422, 'Proyek tidak sesuai dengan proyek penawaran');
                }
                $data['id_proyek'] = (string) $penawaran->id_proyek;
            } elseif ($penawaran !== null && !empty($data['id_proyek'])) {
                abort(422, 'Penawaran belum dijadikan proyek — proyek akan tertaut otomatis saat penawaran dijadikan proyek');
            }
        }

        if (!empty($data['id_proyek']) && !$this->repo->proyekMilikPerusahaan((string) $data['id_proyek'], $idPerusahaan)) {
            abort(404, 'Proyek tidak ditemukan');
        }
    }

    private function pastikanPenawaranMasihBerlaku(PermintaanVendorModel $record): void
    {
        if ($record->id_penawaran === null) {
            return;
        }

        $penawaran = $this->repo->penawaranMilikPerusahaan((string) $record->id_penawaran, (string) $record->id_perusahaan);
        if ($penawaran !== null && $penawaran->status === 'ditolak') {
            abort(422, 'Penawaran asal sudah ditolak — batalkan permintaan vendor ini');
        }
    }

    private function normalisasiUnit(?array $unit, mixed $idJenisFallback, mixed $jumlahFallback): array
    {
        if ($unit !== null) {
            return array_values(array_map(fn (array $baris) => [
                'id_jenis_kendaraan' => $baris['id_jenis_kendaraan'] ?? null,
                'jumlah_unit'        => (int) $baris['jumlah_unit'],
            ], $unit));
        }

        return [[
            'id_jenis_kendaraan' => $idJenisFallback,
            'jumlah_unit'        => (int) ($jumlahFallback ?? 1),
        ]];
    }

    private function validasiUnit(array $unitRows, string $idPerusahaan): void
    {
        $dilihat = [];
        foreach ($unitRows as $baris) {
            $kunci = $baris['id_jenis_kendaraan'] ?? '__kosong__';
            if (in_array($kunci, $dilihat, true)) {
                abort(422, 'Jenis kendaraan duplikat di daftar unit');
            }
            $dilihat[] = $kunci;

            if (!empty($baris['id_jenis_kendaraan']) && !$this->repo->jenisKendaraanMilikPerusahaan((string) $baris['id_jenis_kendaraan'], $idPerusahaan)) {
                abort(404, 'Jenis kendaraan tidak ditemukan');
            }
        }
    }
}
