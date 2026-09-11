<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturItemRepositoryInterface;
use App\Modules\Faktur\Contracts\FakturRepositoryInterface;

class FakturService
{
    private const VALID_TRANSITIONS = [
        'draft'             => ['batal'],
        'menunggu_approval' => ['batal'],
        'terkirim'          => ['lunas', 'batal'],
        'lunas'             => [],
        'batal'             => [],
    ];

    public function __construct(
        private readonly FakturRepositoryInterface $repo,
        private readonly FakturItemRepositoryInterface $itemRepo,
    ) {}

    public function list(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null): array
    {
        $result = $this->repo->paginateByPerusahaan($idPerusahaan, $page, $limit, $search, $status);
        $this->attachDibuatOlehNama($result->items());
        $this->attachPajak($result->items());

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

    public function listByKlien(string $idKlien, string $idPerusahaan, int $page = 1, int $limit = 20): array
    {
        $result = $this->repo->paginateByKlien($idKlien, $idPerusahaan, $page, $limit);
        $this->attachDibuatOlehNama($result->items());
        $this->attachPajak($result->items());

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

    /** @param FakturModel[] $items */
    private function attachDibuatOlehNama(array $items): void
    {
        $idPenggunaList = array_values(array_unique(array_filter(
            array_map(fn (FakturModel $item) => $item->dibuat_oleh, $items)
        )));
        $namaMap = $this->repo->namaPengguna($idPenggunaList);

        foreach ($items as $item) {
            $item->dibuat_oleh_nama = $item->dibuat_oleh !== null ? ($namaMap[$item->dibuat_oleh] ?? null) : null;
        }
    }

    /** @param FakturModel[] $items */
    private function attachPajak(array $items): void
    {
        $idFakturList = array_values(array_unique(array_map(fn (FakturModel $item) => (string) $item->id_faktur, $items)));
        $pajakMap = $this->repo->pajakUntukBanyak($idFakturList);

        foreach ($items as $item) {
            $item->pajak = $pajakMap[(string) $item->id_faktur] ?? [];
            $item->syncOriginalAttribute('pajak');
        }
    }

    /** @return array{0: array, 1: bool} */
    private function tentukanPajak(array $data, ?FakturModel $record = null): array
    {
        if (array_key_exists('pajak', $data)) {
            $rows = array_values(array_map(fn ($r) => [
                'nama'   => (string) $r['nama'],
                'persen' => (float) $r['persen'],
            ], $data['pajak']));
            return [$rows, true];
        }

        if (array_key_exists('persen_pajak', $data)) {
            $nama = array_key_exists('nama_pajak', $data)
                ? $data['nama_pajak']
                : ($record->nama_pajak ?? null);
            $persen = $data['persen_pajak'];
            $rows = $persen !== null ? [['nama' => $nama ?? '', 'persen' => (float) $persen]] : [];
            return [$rows, true];
        }

        return [[], false];
    }

    private function validasiPajakDuplikat(array $pajakRows): void
    {
        $dilihat = [];
        foreach ($pajakRows as $baris) {
            if (in_array($baris['nama'], $dilihat, true)) {
                abort(422, 'Nama pajak duplikat');
            }
            $dilihat[] = $baris['nama'];
        }
    }

    private function totalPajak(float $subtotal, array $pajakRows): float
    {
        return array_sum(array_map(fn ($baris) => $subtotal * ((float) $baris['persen']) / 100, $pajakRows));
    }

    private function tulisKolomLegacy(array &$data, array $pajakRows): void
    {
        $pertama = $pajakRows[0] ?? null;
        $data['nama_pajak']   = $pertama['nama'] ?? null;
        $data['persen_pajak'] = $pertama['persen'] ?? null;
    }

    private function pajakEfektif(FakturModel $record): array
    {
        $rows = $record->pajak ?? [];
        if ($rows !== []) {
            return $rows;
        }
        if ($record->persen_pajak !== null) {
            return [['nama' => $record->nama_pajak, 'persen' => (float) $record->persen_pajak]];
        }
        return [];
    }

    public function findOrFail(string $id, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->repo->findById($id);
        if ($record === null || ($idPerusahaan !== null && (string) $record->id_perusahaan !== $idPerusahaan)) {
            abort(404, 'Invoice tidak ditemukan');
        }
        return $record;
    }

    public function untukCetak(string $id, string $idPerusahaan): FakturModel
    {
        return $this->denganReferensi($this->findOrFail($id, $idPerusahaan));
    }

    public function denganStatusApproval(FakturModel $record): FakturModel
    {
        $record->setAttribute(
            'approval_aktif',
            app(\App\Modules\Approval\ApprovalService::class)->eventTypeAktifAda('faktur', (string) $record->id_perusahaan),
        );
        $record->syncOriginalAttribute('approval_aktif');

        return $record;
    }

    /**
     * Lengkapi faktur dengan nama proyek/klien dan referensi penawaran yang
     * jadi dasar harganya — jejak audit "tarif invoice ini dari kesepakatan
     * mana" untuk halaman detail maupun cetakan PDF/Excel.
     */
    public function denganReferensi(FakturModel $record): FakturModel
    {
        $idPerusahaan = (string) $record->id_perusahaan;

        $record->nama_klien  = $record->id_klien ? $this->repo->namaKlien((string) $record->id_klien, $idPerusahaan) : null;
        $record->nama_proyek = $record->id_proyek ? $this->repo->namaProyek((string) $record->id_proyek, $idPerusahaan) : null;

        $penawaran = $record->id_penawaran
            ? $this->repo->infoPenawaran((string) $record->id_penawaran, $idPerusahaan)
            : null;
        $record->nomor_penawaran = $penawaran->nomor_penawaran ?? null;
        $record->nilai_penawaran = isset($penawaran->nilai_penawaran) ? (float) $penawaran->nilai_penawaran : null;

        return $record;
    }

    public function dataPerusahaan(string $idPerusahaan): ?object
    {
        return $this->repo->getPerusahaan($idPerusahaan);
    }

    public function create(array $data): FakturModel
    {
        if ($this->repo->findByNomor($data['nomor_faktur'], $data['id_perusahaan'])) {
            abort(409, 'Nomor invoice sudah digunakan');
        }

        $items = $data['items'] ?? [];
        $subtotal = collect($items)->sum(fn($i) => $i['qty'] * $i['harga_satuan']);

        [$pajakRows, $pajakDikirim] = $this->tentukanPajak($data);
        if ($pajakDikirim) {
            $this->validasiPajakDuplikat($pajakRows);
        }

        $data['total'] = $subtotal + $this->totalPajak($subtotal, $pajakRows);
        if ($pajakDikirim) {
            $this->tulisKolomLegacy($data, $pajakRows);
        }
        unset($data['items'], $data['pajak']);

        $faktur = $this->repo->create($data);

        foreach ($items as $item) {
            $item['id_faktur'] = $faktur->id_faktur;
            $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
            $this->itemRepo->create($item);
        }

        if ($pajakRows !== []) {
            $this->repo->replacePajak((string) $faktur->id_faktur, $pajakRows);
        }

        $this->repo->insertStatusLog((string) $faktur->id_faktur, (string) ($data['status'] ?? 'draft'), 'Invoice dibuat');

        return $this->repo->findById($faktur->id_faktur);
    }

    public function update(string $id, array $data, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['draft', 'menunggu_approval'], true)) {
            abort(422, 'Hanya invoice berstatus draft atau menunggu approval yang dapat diubah');
        }

        $tarikPengajuan = $record->status === 'menunggu_approval';
        if ($tarikPengajuan) {
            app(\App\Modules\Approval\ApprovalService::class)->batalkanUntukReferensi(
                ['faktur'],
                (string) $record->id_faktur,
                (string) $record->id_perusahaan,
            );
            $data['status'] = 'draft';
            $data['alasan_ditolak_internal'] = null;
        }

        if (isset($data['nomor_faktur']) && $data['nomor_faktur'] !== $record->nomor_faktur) {
            if ($this->repo->findByNomor($data['nomor_faktur'], $record->id_perusahaan)) {
                abort(409, 'Nomor invoice sudah digunakan');
            }
        }

        $itemsBerubah = isset($data['items']);
        if ($itemsBerubah) {
            $items = $data['items'];
            unset($data['items']);

            $this->itemRepo->deleteByFaktur($record->id_faktur);

            foreach ($items as $item) {
                $item['id_faktur'] = $record->id_faktur;
                $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
                $this->itemRepo->create($item);
            }
        }

        [$pajakBaru, $pajakBerubah] = $this->tentukanPajak($data, $record);
        unset($data['pajak']);
        if ($pajakBerubah) {
            $this->validasiPajakDuplikat($pajakBaru);
        }

        if ($itemsBerubah || $pajakBerubah) {
            $subtotal = $itemsBerubah
                ? collect($items)->sum(fn ($i) => $i['qty'] * $i['harga_satuan'])
                : (float) $record->items()->sum('subtotal');
            $pajakUntukHitung = $pajakBerubah ? $pajakBaru : $this->pajakEfektif($record);
            $data['total'] = $subtotal + $this->totalPajak($subtotal, $pajakUntukHitung);
        }

        if ($pajakBerubah) {
            $this->tulisKolomLegacy($data, $pajakBaru);
            $this->repo->replacePajak((string) $record->id_faktur, $pajakBaru);
        }

        $updated = $this->repo->update($record, $data);
        $this->repo->insertStatusLog((string) $record->id_faktur, 'diedit', 'Data invoice diubah');
        if ($tarikPengajuan) {
            $this->repo->insertStatusLog((string) $record->id_faktur, 'draft', 'Pengajuan approval ditarik — kembali ke draft setelah diedit');
        }

        return $updated;
    }

    public function denganAudit(FakturModel $record): FakturModel
    {
        $nama = $this->repo->namaPengguna(array_values(array_filter([
            $record->dibuat_oleh,
            $record->diubah_oleh,
        ])));

        $record->dibuat_oleh_nama = $record->dibuat_oleh !== null ? ($nama[$record->dibuat_oleh] ?? null) : null;
        $record->diubah_oleh_nama = $record->diubah_oleh !== null ? ($nama[$record->diubah_oleh] ?? null) : null;

        return $record;
    }

    public function denganTripTerkait(FakturModel $record): FakturModel
    {
        $record->trip_terkait = $this->repo->tripTerkait((string) $record->id_faktur);

        return $record;
    }

    public function updateStatus(string $id, string $status, ?string $idPerusahaan = null): FakturModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);
        $allowed = self::VALID_TRANSITIONS[$record->status] ?? [];

        if (!in_array($status, $allowed, true)) {
            abort(422, 'Transisi status tidak valid');
        }

        if ($record->status === 'menunggu_approval') {
            app(\App\Modules\Approval\ApprovalService::class)->batalkanUntukReferensi(
                ['faktur'],
                (string) $record->id_faktur,
                (string) $record->id_perusahaan,
            );
        }

        $updated = $this->repo->update($record, ['status' => $status]);
        $this->repo->insertStatusLog((string) $record->id_faktur, $status);

        return $updated;
    }

    public function ajukanApproval(string $id, string $idPengguna, string $idPerusahaan): FakturModel
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if ($record->status !== 'draft') {
            abort(422, 'Hanya invoice berstatus draft yang bisa diajukan approval');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($id, $idPerusahaan, $idPengguna) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Invoice tidak ditemukan');
            }
            if ($terkunci->status !== 'draft') {
                abort(422, 'Hanya invoice berstatus draft yang bisa diajukan approval');
            }

            $approvalService = app(\App\Modules\Approval\ApprovalService::class);

            if (!$approvalService->eventTypeAktifAda('faktur', $idPerusahaan)) {
                $updated = $this->repo->update($terkunci, [
                    'status'                  => 'terkirim',
                    'alasan_ditolak_internal' => null,
                ]);
                $this->repo->insertStatusLog($id, 'terkirim', 'Ditandai terkirim — approval internal nonaktif');

                return $updated;
            }

            $approvalService->ajukan(
                'faktur',
                $id,
                $idPengguna,
                (float) $terkunci->total,
                $idPerusahaan,
            );

            $updated = $this->repo->update($terkunci, [
                'status'                  => 'menunggu_approval',
                'alasan_ditolak_internal' => null,
            ]);
            $this->repo->insertStatusLog($id, 'menunggu_approval', 'Diajukan untuk approval internal');

            return $updated;
        });
    }

    public function terapkanKeputusanApproval(string $idFaktur, string $idPerusahaan, string $idPengguna, string $keputusan, ?string $alasanDitolak): void
    {
        $record = $this->repo->findById($idFaktur);
        if ($record === null || $record->id_perusahaan !== $idPerusahaan) {
            \Illuminate\Support\Facades\Log::warning("FakturApprovalListener: faktur {$idFaktur} tidak ditemukan atau beda perusahaan");
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
            $this->repo->insertStatusLog($idFaktur, 'draft', 'Approval ditolak — dikembalikan ke draft');
            return;
        }

        $this->repo->update($record, ['status' => 'terkirim']);
        $this->repo->insertStatusLog($idFaktur, 'terkirim', 'Approval disetujui');
    }

    public function riwayatStatus(FakturModel $record): array
    {
        $rows = $this->repo->listStatusLog((string) $record->id_faktur);

        $adaLogAwal = false;
        foreach ($rows as $row) {
            if ($row['status'] === 'draft') {
                $adaLogAwal = true;
                break;
            }
        }

        if (!$adaLogAwal) {
            $nama = $this->repo->namaPengguna(array_values(array_filter([$record->dibuat_oleh])));
            array_unshift($rows, [
                'status'     => 'draft',
                'keterangan' => 'Invoice dibuat',
                'waktu'      => $record->dibuat_pada,
                'oleh'       => $record->dibuat_oleh !== null ? ($nama[$record->dibuat_oleh] ?? null) : null,
            ]);
        }

        return $rows;
    }

    public function delete(string $id, ?string $idPerusahaan = null): void
    {
        $record = $this->findOrFail($id, $idPerusahaan);

        if (!in_array($record->status, ['draft', 'batal'], true)) {
            abort(422, 'Hanya invoice berstatus draft atau batal yang dapat dihapus');
        }

        $this->itemRepo->deleteByFaktur($record->id_faktur);
        $this->repo->replacePajak((string) $record->id_faktur, []);
        $this->repo->delete($record);
    }
}
