<?php

declare(strict_types=1);

namespace App\Modules\Penawaran;

use App\Modules\Klien\Contracts\KlienRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranItemRepositoryInterface;
use App\Modules\Penawaran\Contracts\PenawaranRepositoryInterface;
use App\Modules\Penawaran\Mail\PenawaranDikirimMail;
use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use App\Modules\ProyekRute\Contracts\ProyekRuteRepositoryInterface;
use App\Support\HtmlAman;
use App\Support\KodeOtomatis;
use App\Support\TipeHarga;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PenawaranService
{
    private const VALID_TRANSITIONS = [
        'draft'     => [],
        'terkirim'  => ['negosiasi', 'disetujui', 'ditolak'],
        'negosiasi' => ['disetujui', 'ditolak'],
        'disetujui' => [],
        'ditolak'   => [],
    ];

    private const STATUS_BELUM_BISA_KIRIM_EMAIL = ['draft', 'menunggu_approval'];

    public function __construct(
        private readonly PenawaranRepositoryInterface $repo,
        private readonly PenawaranItemRepositoryInterface $itemRepo,
        private readonly ProyekRepositoryInterface $proyekRepo,
        private readonly ProyekRuteRepositoryInterface $proyekRuteRepo,
        private readonly KlienRepositoryInterface $klienRepo,
    ) {}

    public function list(
        string $idPerusahaan,
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        ?string $status = null,
        ?string $idProyek = null
    ): array {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status, $idProyek);

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

    public function findOrFail(string $id, string $idPerusahaan): PenawaranModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Penawaran tidak ditemukan');
        }
        $record->setRelation('items', $this->itemRepo->listByPenawaran($id));
        return $record;
    }

    public function detailDenganInfoProyek(string $id, string $idPerusahaan): PenawaranModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        $record->setAttribute(
            'approval_aktif',
            app(\App\Modules\Approval\ApprovalService::class)->eventTypeAktifAda('penawaran', $idPerusahaan),
        );
        $record->syncOriginalAttribute('approval_aktif');

        if ($record->id_klien !== null) {
            $klien = $this->klienRepo->findById((string) $record->id_klien);
            $record->setAttribute('nama_klien', $klien->nama_klien ?? null);
            $record->syncOriginalAttribute('nama_klien');
            $record->setAttribute('email_klien', $klien->email ?? null);
            $record->syncOriginalAttribute('email_klien');
        }

        if ($record->id_proyek !== null) {
            $proyek = $this->proyekRepo->findById((string) $record->id_proyek);
            // Atribut tempelan bukan kolom tabel — sinkronkan ke original supaya
            // tidak dianggap dirty dan ikut tersimpan bila model ini di-update.
            $record->setAttribute('proyek_status', $proyek->status ?? null);
            $record->syncOriginalAttribute('proyek_status');
            $record->setAttribute('kode_proyek', $proyek->kode_proyek ?? null);
            $record->syncOriginalAttribute('kode_proyek');
            $record->setAttribute('nama_proyek', $proyek->nama_proyek ?? null);
            $record->syncOriginalAttribute('nama_proyek');
        }

        return $record;
    }

    public function create(array $data): PenawaranModel
    {
        $idPerusahaan = $data['id_perusahaan'];

        $data['nomor_penawaran'] = KodeOtomatis::berikutnya($idPerusahaan, 'penawaran');

        if (array_key_exists('catatan', $data)) {
            $data['catatan'] = HtmlAman::bersihkan($data['catatan']);
        }

        if ($this->repo->findByNomor($idPerusahaan, $data['nomor_penawaran'])) {
            abort(409, 'Nomor penawaran sudah digunakan');
        }

        $items = $data['items'] ?? [];
        $this->pastikanItemTidakDuplikat($items);
        unset($data['items']);
        $tipeHarga = $data['tipe_harga'] ?? 'per_rit';
        $data['tipe_harga'] = $tipeHarga;
        if (count($items) > 0 && TipeHarga::perRit($tipeHarga)) {
            $data['nilai_penawaran'] = $this->totalItems($items);
        }

        $data['id_penawaran'] = (string) Str::uuid();
        $data['status']       = $data['status'] ?? 'draft';

        return DB::transaction(function () use ($data, $items, $idPerusahaan) {
            $record = $this->repo->create($data);

            foreach ($items as $item) {
                $this->simpanItem($record, $item);
            }

            return $this->findOrFail($record->id_penawaran, $idPerusahaan);
        });
    }

    public function update(string $id, array $data, string $idPerusahaan): PenawaranModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['draft', 'negosiasi'], true)) {
            abort(422, 'Hanya penawaran berstatus draft atau negosiasi yang dapat diubah');
        }

        if (array_key_exists('catatan', $data)) {
            $data['catatan'] = HtmlAman::bersihkan($data['catatan']);
        }

        if (isset($data['nomor_penawaran']) && $data['nomor_penawaran'] !== $record->nomor_penawaran) {
            if ($this->repo->findByNomor($idPerusahaan, $data['nomor_penawaran'], $id)) {
                abort(409, 'Nomor penawaran sudah digunakan');
            }
        }

        return DB::transaction(function () use ($record, $data, $idPerusahaan) {
            if (array_key_exists('items', $data)) {
                $items = $data['items'] ?? [];
                $this->pastikanItemTidakDuplikat($items);
                unset($data['items']);

                $this->itemRepo->deleteByPenawaran($record->id_penawaran);
                foreach ($items as $item) {
                    $this->simpanItem($record, $item);
                }
                $tipeHarga = $data['tipe_harga'] ?? $record->tipe_harga ?? 'per_rit';
                if (count($items) > 0 && TipeHarga::perRit($tipeHarga)) {
                    $data['nilai_penawaran'] = $this->totalItems($items);
                }
            }

            $this->repo->update($record, $data);

            return $this->findOrFail($record->id_penawaran, $idPerusahaan);
        });
    }

    public function updateStatus(string $id, string $newStatus, string $idPerusahaan): PenawaranModel
    {
        $record  = $this->findOrFail($id, $idPerusahaan);
        $allowed = self::VALID_TRANSITIONS[$record->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            abort(422, 'Transisi status tidak valid');
        }

        return DB::transaction(function () use ($id, $newStatus, $idPerusahaan) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Penawaran tidak ditemukan');
            }

            $allowedTerkunci = self::VALID_TRANSITIONS[$terkunci->status] ?? [];
            if (!in_array($newStatus, $allowedTerkunci, true)) {
                abort(422, 'Transisi status tidak valid');
            }

            $updated = $this->repo->update($terkunci, ['status' => $newStatus]);

            if ($newStatus === 'disetujui' && $terkunci->id_penawaran_induk !== null) {
                $this->tulisBalikRateCard($updated);
            }

            $updated->setRelation('items', $this->itemRepo->listByPenawaran($updated->id_penawaran));

            return $updated;
        });
    }

    public function ajukanApproval(string $id, string $idPengguna, string $idPerusahaan): PenawaranModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($record->status !== 'draft') {
            abort(422, 'Hanya penawaran berstatus draft yang bisa diajukan approval');
        }

        if ($record->id_klien === null) {
            abort(422, 'Lengkapi klien terlebih dahulu sebelum mengajukan approval');
        }

        if (TipeHarga::perRit($record->tipe_harga) && $this->itemRepo->listByPenawaran($id)->isEmpty()) {
            abort(422, 'Penawaran belum punya item rute — tambahkan minimal 1 rute sebelum diajukan approval');
        }

        return DB::transaction(function () use ($id, $idPerusahaan, $idPengguna) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Penawaran tidak ditemukan');
            }
            if ($terkunci->status !== 'draft') {
                abort(422, 'Hanya penawaran berstatus draft yang bisa diajukan approval');
            }
            if ($terkunci->id_klien === null) {
                abort(422, 'Lengkapi klien terlebih dahulu sebelum mengajukan approval');
            }
            if (TipeHarga::perRit($terkunci->tipe_harga) && $this->itemRepo->listByPenawaran($id)->isEmpty()) {
                abort(422, 'Penawaran belum punya item rute — tambahkan minimal 1 rute sebelum diajukan approval');
            }

            $approvalService = app(\App\Modules\Approval\ApprovalService::class);

            if (!$approvalService->eventTypeAktifAda('penawaran', $idPerusahaan)) {
                return $this->repo->update($terkunci, [
                    'status'                  => 'terkirim',
                    'alasan_ditolak_internal' => null,
                ]);
            }

            $approvalService->ajukan(
                'penawaran',
                $id,
                $idPengguna,
                $terkunci->nilai_penawaran !== null ? (float) $terkunci->nilai_penawaran : null,
                $idPerusahaan,
            );

            return $this->repo->update($terkunci, [
                'status'                   => 'menunggu_approval',
                'alasan_ditolak_internal'  => null,
            ]);
        });
    }

    public function terapkanKeputusanApproval(string $idPenawaran, string $idPerusahaan, string $idPengguna, string $keputusan, ?string $alasanDitolak): void
    {
        $record = $this->repo->findById($idPenawaran);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            \Illuminate\Support\Facades\Log::warning("PenawaranApprovalListener: penawaran {$idPenawaran} tidak ditemukan atau beda perusahaan");
            return;
        }
        if ($record->status !== 'menunggu_approval') {
            return;
        }

        if ($keputusan === 'ditolak') {
            $this->repo->update($record, [
                'status'                  => 'draft',
                'alasan_ditolak_internal' => $alasanDitolak,
            ]);
            return;
        }

        $this->repo->update($record, ['status' => 'terkirim']);
    }

    public function kirimEmail(string $id, string $idPerusahaan, string $pdfBinary, string $emailTujuan, string $subjek, string $pesan, array $lampiranTambahan = []): PenawaranModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (in_array($record->status, self::STATUS_BELUM_BISA_KIRIM_EMAIL, true)) {
            abort(422, 'Penawaran berstatus draft atau menunggu approval belum bisa dikirim ke klien');
        }

        $terkirim = Mail::to($emailTujuan)->send(new PenawaranDikirimMail(
            $record,
            $subjek,
            HtmlAman::untukTampilan($pesan),
            $pdfBinary,
            'penawaran-' . $record->nomor_penawaran . '.pdf',
            $lampiranTambahan,
        ));

        $updated = $this->repo->update($record, [
            'email_terkirim_ke'   => $emailTujuan,
            'email_terkirim_pada' => now(),
            'email_message_id'    => $terkirim?->getMessageId(),
            'email_gagal_pada'    => null,
            'email_gagal_alasan'  => null,
        ]);
        $updated->setRelation('items', $this->itemRepo->listByPenawaran($updated->id_penawaran));

        return $updated;
    }

    private function tulisBalikRateCard(PenawaranModel $penawaran): void
    {
        if ($penawaran->id_proyek === null) {
            return;
        }

        if (TipeHarga::perRit($penawaran->tipe_harga)) {
            $kunciDipertahankan = [];

            foreach ($this->itemRepo->listByPenawaran($penawaran->id_penawaran) as $item) {
                $kunciDipertahankan[] = json_encode([$item->id_rute, $item->id_jenis_kendaraan]);
                $baris = $this->proyekRuteRepo->findBarisTepat($penawaran->id_proyek, $item->id_rute, $item->id_jenis_kendaraan);

                if ($baris !== null) {
                    $this->proyekRuteRepo->update($baris, [
                        'harga_penawaran' => $item->harga_satuan,
                        'estimasi_ritase' => $item->estimasi_ritase,
                    ]);
                } else {
                    $this->proyekRuteRepo->create([
                        'id_perusahaan'      => $penawaran->id_perusahaan,
                        'id_proyek'          => $penawaran->id_proyek,
                        'id_rute'            => $item->id_rute,
                        'id_jenis_kendaraan' => $item->id_jenis_kendaraan,
                        'harga_penawaran'    => $item->harga_satuan,
                        'estimasi_ritase'    => $item->estimasi_ritase,
                    ]);
                }
            }

            foreach ($this->proyekRuteRepo->listByProyek($penawaran->id_proyek) as $baris) {
                $kunci = json_encode([$baris->id_rute, $baris->id_jenis_kendaraan]);
                if (!in_array($kunci, $kunciDipertahankan, true)) {
                    $this->proyekRuteRepo->delete($baris);
                }
            }
        }

        $proyek = $this->proyekRepo->findById($penawaran->id_proyek);
        if ($proyek !== null) {
            $this->proyekRepo->update($proyek, ['harga_penawaran' => $penawaran->nilai_penawaran]);
        }
    }

    public function delete(string $id, string $idPerusahaan): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['draft', 'menunggu_approval'], true)) {
            abort(422, 'Hanya penawaran berstatus draft atau menunggu approval yang dapat dihapus');
        }

        if ($record->status === 'menunggu_approval') {
            app(\App\Modules\Approval\ApprovalService::class)
                ->batalkanUntukReferensi(['penawaran'], $id, $idPerusahaan);
        }

        $this->repo->delete($record);
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    private function pastikanItemTidakDuplikat(array $items): void
    {
        $kunci = [];
        foreach ($items as $item) {
            $k = json_encode([$item['id_rute'] ?? null, $item['id_jenis_kendaraan'] ?? null]);
            if (in_array($k, $kunci, true)) {
                abort(422, 'Terdapat rute duplikat dalam item penawaran');
            }
            $kunci[] = $k;
        }
    }

    private function totalItems(array $items): float
    {
        return collect($items)->sum(
            fn (array $i) => (float) ($i['harga_satuan'] ?? 0) * (int) ($i['estimasi_ritase'] ?? 1)
        );
    }

    private function simpanItem(PenawaranModel $penawaran, array $item): void
    {
        if ($this->itemRepo->ruteMilik($item['id_rute'], $penawaran->id_perusahaan) === null) {
            abort(404, 'Rute tidak ditemukan');
        }
        if ($this->itemRepo->jenisKendaraanMilik($item['id_jenis_kendaraan'], $penawaran->id_perusahaan) === null) {
            abort(404, 'Jenis kendaraan tidak ditemukan');
        }

        $ritase      = (int) ($item['estimasi_ritase'] ?? 1);
        $hargaSatuan = $item['harga_satuan'] ?? null;
        $this->itemRepo->create([
            'id_perusahaan'      => $penawaran->id_perusahaan,
            'id_penawaran'       => $penawaran->id_penawaran,
            'id_rute'            => $item['id_rute'],
            'id_jenis_kendaraan' => $item['id_jenis_kendaraan'],
            'harga_satuan'       => $hargaSatuan,
            'estimasi_ritase'    => $ritase,
            'jumlah_hari'        => $item['jumlah_hari'] ?? null,
            'subtotal'           => $hargaSatuan !== null ? (float) $hargaSatuan * $ritase : 0,
            'keterangan'         => $item['keterangan'] ?? null,
        ]);
    }
}