<?php

declare(strict_types=1);

namespace App\Modules\Faktur;

use App\Modules\Faktur\Contracts\FakturItemRepositoryInterface;
use App\Modules\Faktur\Contracts\FakturRepositoryInterface;
use App\Support\PenyimpananBerkas;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FakturService
{
    private const BATAS_POTONGAN_PERSEN = 10;

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
        $this->attachPembayaran($result->items());

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
        $this->attachPembayaran($result->items());

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

        foreach (array_values($items) as $i => $item) {
            $item['id_faktur'] = $faktur->id_faktur;
            $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
            $item['urutan']    = $i + 1;
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

            foreach (array_values($items) as $i => $item) {
                $item['id_faktur'] = $record->id_faktur;
                $item['subtotal']  = $item['qty'] * $item['harga_satuan'];
                $item['urutan']    = $i + 1;
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
                : (float) $record->items()->reorder()->sum('subtotal');
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
        $this->findOrFail($id, $idPerusahaan);

        return DB::transaction(function () use ($id, $status) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null) {
                abort(404, 'Invoice tidak ditemukan');
            }
            $allowed = self::VALID_TRANSITIONS[$terkunci->status] ?? [];
            if (!in_array($status, $allowed, true)) {
                abort(422, 'Transisi status tidak valid');
            }

            $terbayar = $this->repo->totalPembayaran($id);
            if ($status === 'batal' && $terbayar > 0) {
                abort(422, 'Invoice ini sudah menerima pembayaran — hapus pembayarannya dulu sebelum membatalkan');
            }

            $perubahan = ['status' => $status];
            if ($status === 'lunas') {
                if (round((float) $terkunci->total - $terbayar, 2) >= 1) {
                    abort(422, 'Invoice lunas otomatis saat tagihannya habis — catat pembayarannya lewat Catat Pembayaran');
                }
                $perubahan['tanggal_lunas'] = $this->repo->tanggalBayarTerakhir($id) ?? now()->toDateString();
            }

            if ($terkunci->status === 'menunggu_approval') {
                app(\App\Modules\Approval\ApprovalService::class)->batalkanUntukReferensi(
                    ['faktur'],
                    $id,
                    (string) $terkunci->id_perusahaan,
                );
            }

            $this->repo->update($terkunci, $perubahan);
            $this->repo->insertStatusLog($id, $status);

            return $this->repo->findById($id);
        });
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

    /** @param FakturModel[] $items */
    private function attachPembayaran(array $items): void
    {
        $idFakturList = array_values(array_unique(array_map(fn (FakturModel $item) => (string) $item->id_faktur, $items)));
        $peta = $this->repo->pembayaranUntukBanyak($idFakturList);

        foreach ($items as $item) {
            $this->setRingkasanPembayaran($item, $peta[(string) $item->id_faktur] ?? ['diterima' => 0.0, 'potongan' => 0.0]);
        }
    }

    private function setRingkasanPembayaran(FakturModel $record, array $bayar): void
    {
        $terbayar = round($bayar['diterima'] + $bayar['potongan'], 2);
        $nilai = [
            'diterima'       => round($bayar['diterima'], 2),
            'potongan_bayar' => round($bayar['potongan'], 2),
            'terbayar'       => $terbayar,
            'sisa'           => $record->status === 'lunas' ? 0.0 : round(max(0, (float) $record->total - $terbayar), 2),
        ];
        foreach ($nilai as $kunci => $angka) {
            $record->setAttribute($kunci, $angka);
            $record->syncOriginalAttribute($kunci);
        }
    }

    public function denganPembayaran(FakturModel $record): FakturModel
    {
        $baris = $this->repo->listPembayaran((string) $record->id_faktur);

        $record->setAttribute('pembayaran', array_map(fn ($b) => [
            'id_pembayaran_faktur' => $b->id_pembayaran_faktur,
            'tanggal_bayar'        => substr((string) $b->tanggal_bayar, 0, 10),
            'nominal'              => (float) $b->nominal,
            'potongan'             => (float) $b->potongan,
            'keterangan_potongan'  => $b->keterangan_potongan,
            'no_referensi'         => $b->no_referensi,
            'url_bukti'            => PenyimpananBerkas::url($b->url_bukti),
            'catatan'              => $b->catatan,
            'dicatat_oleh'         => $b->dicatat_oleh,
            'dibuat_pada'          => $b->dibuat_pada,
        ], $baris));
        $record->syncOriginalAttribute('pembayaran');

        $this->setRingkasanPembayaran($record, [
            'diterima' => array_sum(array_map(fn ($b) => (float) $b->nominal, $baris)),
            'potongan' => array_sum(array_map(fn ($b) => (float) $b->potongan, $baris)),
        ]);

        return $record;
    }

    public function catatPembayaran(string $id, array $data, ?UploadedFile $bukti, string $idPerusahaan): FakturModel
    {
        $nominal = round((float) $data['nominal'], 2);
        $potongan = round((float) ($data['potongan'] ?? 0), 2);
        if ($nominal + $potongan <= 0) {
            abort(422, 'Isi nominal yang diterima atau potongannya');
        }
        $keteranganPotongan = trim((string) ($data['keterangan_potongan'] ?? ''));
        if ($potongan > 0 && $keteranganPotongan === '') {
            abort(422, 'Isi keterangan potongan, misalnya PPh 23 atau biaya transfer');
        }

        return DB::transaction(function () use ($id, $data, $bukti, $idPerusahaan, $nominal, $potongan, $keteranganPotongan) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || (string) $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Invoice tidak ditemukan');
            }
            if ($terkunci->status !== 'terkirim') {
                abort(422, 'Pembayaran hanya bisa dicatat untuk invoice berstatus terkirim');
            }

            $total = round((float) $terkunci->total, 2);
            $sudah = $this->repo->pembayaranUntukBanyak([$id])[$id] ?? ['diterima' => 0.0, 'potongan' => 0.0];
            $sisa = round($total - $sudah['diterima'] - $sudah['potongan'], 2);
            $masuk = round($nominal + $potongan, 2);
            $batas = (float) ceil($sisa);
            if ($masuk > $batas) {
                abort(422, 'Pembayaran melebihi sisa tagihan (Rp ' . number_format($batas, 0, ',', '.') . ')');
            }
            if ($potongan > 0) {
                $batasPotongan = round($total * self::BATAS_POTONGAN_PERSEN / 100, 2);
                if (round($sudah['potongan'] + $potongan, 2) > $batasPotongan) {
                    abort(422, 'Total potongan melebihi ' . self::BATAS_POTONGAN_PERSEN . '% nilai invoice (maksimal Rp '
                        . number_format($batasPotongan, 0, ',', '.') . ') — selisih sebesar itu perlu koreksi invoice, bukan potongan');
                }
            }

            $noReferensi = trim((string) ($data['no_referensi'] ?? ''));
            $catatan = trim((string) ($data['catatan'] ?? ''));
            $this->repo->insertPembayaran([
                'id_faktur'           => $id,
                'tanggal_bayar'       => $data['tanggal_bayar'],
                'nominal'             => $nominal,
                'potongan'            => $potongan,
                'keterangan_potongan' => $potongan > 0 ? $keteranganPotongan : null,
                'no_referensi'        => $noReferensi !== '' ? $noReferensi : null,
                'url_bukti'           => $bukti !== null ? PenyimpananBerkas::simpan($bukti, 'bukti-pembayaran-klien') : null,
                'catatan'             => $catatan !== '' ? $catatan : null,
            ]);

            $sisaBaru = round($sisa - $masuk, 2);
            $this->repo->insertStatusLog($id, 'pembayaran', $this->keteranganPembayaran($nominal, $potongan, $keteranganPotongan, $sisaBaru));

            if ($sisaBaru < 1) {
                $this->repo->update($terkunci, [
                    'status'        => 'lunas',
                    'tanggal_lunas' => $this->repo->tanggalBayarTerakhir($id) ?? $data['tanggal_bayar'],
                ]);
                $this->repo->insertStatusLog($id, 'lunas', 'Lunas — seluruh tagihan sudah dibayar');
            }

            return $this->repo->findById($id);
        });
    }

    private function keteranganPembayaran(float $nominal, float $potongan, string $keteranganPotongan, float $sisa): string
    {
        $rupiah = fn (float $angka) => 'Rp ' . number_format($angka, 0, ',', '.');
        $teks = 'Diterima ' . $rupiah($nominal);
        if ($potongan > 0) {
            $teks .= ', potongan ' . $rupiah($potongan) . ($keteranganPotongan !== '' ? " ({$keteranganPotongan})" : '');
        }
        $teks .= $sisa >= 1 ? ' — sisa ' . $rupiah($sisa) : ' — tagihan lunas';

        return Str::limit($teks, 250, '…');
    }

    public function hapusPembayaran(string $id, string $idPembayaran, string $alasan, string $idPerusahaan): FakturModel
    {
        return DB::transaction(function () use ($id, $idPembayaran, $alasan, $idPerusahaan) {
            $terkunci = $this->repo->findForUpdate($id);
            if ($terkunci === null || (string) $terkunci->id_perusahaan !== $idPerusahaan) {
                abort(404, 'Invoice tidak ditemukan');
            }
            if (!in_array($terkunci->status, ['terkirim', 'lunas'], true)) {
                abort(422, 'Pembayaran invoice ini tidak bisa diubah pada status sekarang');
            }
            $bayar = $this->repo->findPembayaran($id, $idPembayaran);
            if ($bayar === null) {
                abort(404, 'Pembayaran tidak ditemukan');
            }

            $this->repo->softDeletePembayaran($idPembayaran);
            $this->repo->insertStatusLog($id, 'pembayaran_dihapus', Str::limit(sprintf(
                'Pembayaran Rp %s tanggal %s dihapus: %s',
                number_format((float) $bayar->nominal + (float) $bayar->potongan, 0, ',', '.'),
                date('d/m/Y', strtotime((string) $bayar->tanggal_bayar)),
                trim($alasan),
            ), 250, '…'));

            $sisa = round((float) $terkunci->total - $this->repo->totalPembayaran($id), 2);
            if ($terkunci->status === 'lunas' && $sisa >= 1) {
                $this->repo->update($terkunci, ['status' => 'terkirim', 'tanggal_lunas' => null]);
                $this->repo->insertStatusLog($id, 'terkirim', 'Kembali ke terkirim — pembayaran dihapus');
            }

            return $this->repo->findById($id);
        });
    }

    public function outstanding(string $idPerusahaan, array $filter = [], ?int $page = 1, int $limit = 10): array
    {
        $hariIni = Carbon::today();
        $aging = [
            'belum_jatuh_tempo' => ['jumlah' => 0, 'nominal' => 0.0],
            'hari_1_30'         => ['jumlah' => 0, 'nominal' => 0.0],
            'hari_31_60'        => ['jumlah' => 0, 'nominal' => 0.0],
            'di_atas_60'        => ['jumlah' => 0, 'nominal' => 0.0],
        ];
        $lewat = ['jumlah' => 0, 'nominal' => 0.0];
        $segera = ['jumlah' => 0, 'nominal' => 0.0];
        $totalTagihan = 0.0;
        $totalTerbayar = 0.0;
        $totalSisa = 0.0;
        $perKlien = [];
        $rows = [];

        foreach ($this->repo->outstanding($idPerusahaan) as $row) {
            $total = round((float) $row->total, 2);
            $terbayar = round((float) $row->terbayar, 2);
            $sisa = round(max(0, $total - $terbayar), 2);
            if ($sisa <= 0) {
                continue;
            }

            $hariTerlambat = 0;
            $hariMenuju = null;
            if ($row->jatuh_tempo !== null) {
                $jatuhTempo = Carbon::parse((string) $row->jatuh_tempo)->startOfDay();
                $selisih = (int) round($hariIni->diffInDays($jatuhTempo, false));
                if ($selisih < 0) {
                    $hariTerlambat = -$selisih;
                } else {
                    $hariMenuju = $selisih;
                }
            }
            $kelompok = match (true) {
                $hariTerlambat <= 0  => 'belum_jatuh_tempo',
                $hariTerlambat <= 30 => 'hari_1_30',
                $hariTerlambat <= 60 => 'hari_31_60',
                default              => 'di_atas_60',
            };

            $aging[$kelompok]['jumlah']++;
            $aging[$kelompok]['nominal'] = round($aging[$kelompok]['nominal'] + $sisa, 2);
            if ($hariTerlambat > 0) {
                $lewat['jumlah']++;
                $lewat['nominal'] = round($lewat['nominal'] + $sisa, 2);
            } elseif ($hariMenuju !== null && $hariMenuju <= 7) {
                $segera['jumlah']++;
                $segera['nominal'] = round($segera['nominal'] + $sisa, 2);
            }
            $totalTagihan = round($totalTagihan + $total, 2);
            $totalTerbayar = round($totalTerbayar + $terbayar, 2);
            $totalSisa = round($totalSisa + $sisa, 2);

            $kunciKlien = (string) ($row->id_klien ?? '');
            $perKlien[$kunciKlien] ??= [
                'id_klien'    => $row->id_klien,
                'nama_klien'  => $row->nama_klien ?? 'Tanpa klien',
                'jumlah'      => 0,
                'outstanding' => 0.0,
                'terlambat'   => 0.0,
            ];
            $perKlien[$kunciKlien]['jumlah']++;
            $perKlien[$kunciKlien]['outstanding'] = round($perKlien[$kunciKlien]['outstanding'] + $sisa, 2);
            if ($hariTerlambat > 0) {
                $perKlien[$kunciKlien]['terlambat'] = round($perKlien[$kunciKlien]['terlambat'] + $sisa, 2);
            }

            $rows[] = [
                'id_faktur'      => $row->id_faktur,
                'nomor_faktur'   => $row->nomor_faktur,
                'id_klien'       => $row->id_klien,
                'nama_klien'     => $row->nama_klien,
                'nama_proyek'    => $row->nama_proyek,
                'tanggal_faktur' => $row->tanggal_faktur !== null ? substr((string) $row->tanggal_faktur, 0, 10) : null,
                'jatuh_tempo'    => $row->jatuh_tempo !== null ? substr((string) $row->jatuh_tempo, 0, 10) : null,
                'hari_terlambat' => $hariTerlambat,
                'kelompok'       => $kelompok,
                'total'          => $total,
                'terbayar'       => $terbayar,
                'sisa'           => $sisa,
            ];
        }

        usort($perKlien, fn (array $a, array $b) => $b['outstanding'] <=> $a['outstanding']);
        usort($rows, function (array $a, array $b) {
            if ($a['hari_terlambat'] !== $b['hari_terlambat']) {
                return $b['hari_terlambat'] <=> $a['hari_terlambat'];
            }
            return strcmp($a['jatuh_tempo'] ?? '9999-12-31', $b['jatuh_tempo'] ?? '9999-12-31')
                ?: strcmp((string) $a['nomor_faktur'], (string) $b['nomor_faktur']);
        });

        $idKlien = (string) ($filter['id_klien'] ?? '');
        $kelompokDipilih = (string) ($filter['kelompok'] ?? '');
        $cari = mb_strtolower(trim((string) ($filter['search'] ?? '')));
        $tersaring = array_values(array_filter($rows, function (array $r) use ($idKlien, $kelompokDipilih, $cari) {
            if ($idKlien !== '' && (string) $r['id_klien'] !== $idKlien) {
                return false;
            }
            if ($kelompokDipilih === 'terlambat' && $r['hari_terlambat'] <= 0) {
                return false;
            }
            if ($kelompokDipilih !== '' && $kelompokDipilih !== 'terlambat' && $r['kelompok'] !== $kelompokDipilih) {
                return false;
            }
            if ($cari !== '' && !str_contains(mb_strtolower($r['nomor_faktur'] . ' ' . ($r['nama_klien'] ?? '')), $cari)) {
                return false;
            }
            return true;
        }));

        $jumlahTersaring = count($tersaring);
        if ($page !== null) {
            $limit = max(1, $limit);
            $page = max(1, $page);
            $data = array_slice($tersaring, ($page - 1) * $limit, $limit);
            $meta = ['page' => $page, 'limit' => $limit, 'total' => $jumlahTersaring, 'totalPages' => max(1, (int) ceil($jumlahTersaring / $limit))];
        } else {
            $data = $tersaring;
            $meta = ['page' => 1, 'limit' => $jumlahTersaring, 'total' => $jumlahTersaring, 'totalPages' => 1];
        }

        return [
            'ringkasan' => [
                'jumlah_invoice'     => count($rows),
                'total_tagihan'      => $totalTagihan,
                'total_terbayar'     => $totalTerbayar,
                'total_outstanding'  => $totalSisa,
                'lewat_jatuh_tempo'  => $lewat,
                'jatuh_tempo_7_hari' => $segera,
                'diterima_bulan_ini' => $this->repo->diterimaAntara(
                    $idPerusahaan,
                    $hariIni->copy()->startOfMonth()->toDateString(),
                    $hariIni->copy()->endOfMonth()->toDateString(),
                ),
                'aging'              => $aging,
            ],
            'per_klien' => array_values($perKlien),
            'data'      => $data,
            'meta'      => $meta,
        ];
    }
}
