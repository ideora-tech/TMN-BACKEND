<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Modules\Absensi\AbsensiService;
use App\Modules\ArusKas\ArusKasService;
use App\Modules\Payroll\Contracts\PayrollRepositoryInterface;
use App\Modules\Payroll\Imports\PayrollImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class PayrollService
{
    private const BULAN_ID = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    // PTKP tahunan (UU HPP): dasar TK/0 54 juta, +4,5 juta per status kawin & per tanggungan (maks 3)
    private const PTKP_DASAR    = 54000000;
    private const PTKP_TAMBAHAN = 4500000;

    public function __construct(
        private readonly PayrollRepositoryInterface $repo,
        private readonly AbsensiService $absensiService,
        private readonly ArusKasService $arusKasService,
    ) {}

    // ── Pengaturan ───────────────────────────────────────────────

    public function pengaturan(string $idPerusahaan): array
    {
        $row = $this->repo->getPengaturan($idPerusahaan);

        return [
            'tanggal_mulai_cutoff'       => $row ? (int) $row->tanggal_mulai_cutoff : 21,
            'hari_kerja_per_bulan'       => $row ? (int) $row->hari_kerja_per_bulan : 25,
            'persen_bpjs_kesehatan'      => $row ? (float) $row->persen_bpjs_kesehatan : 1.0,
            'persen_bpjs_jht'            => $row ? (float) $row->persen_bpjs_jht : 2.0,
            'persen_bpjs_jp'             => $row ? (float) $row->persen_bpjs_jp : 1.0,
            'plafon_gaji_bpjs_kesehatan' => $row ? (float) $row->plafon_gaji_bpjs_kesehatan : 12000000.0,
            'ptkp_dasar'                 => $row ? (float) ($row->ptkp_dasar ?? self::PTKP_DASAR) : (float) self::PTKP_DASAR,
            'ptkp_tambahan'              => $row ? (float) ($row->ptkp_tambahan ?? self::PTKP_TAMBAHAN) : (float) self::PTKP_TAMBAHAN,
        ];
    }

    public function simpanPengaturan(string $idPerusahaan, array $data): array
    {
        $this->repo->upsertPengaturan($idPerusahaan, $data);
        return $this->pengaturan($idPerusahaan);
    }

    // ── Periode ──────────────────────────────────────────────────

    /** Rentang periode untuk "bulan gajian" YYYY-MM berdasarkan cut-off. Cut-off 21 & bulan Juli = 21 Jun s/d 20 Jul; cut-off 1 = bulan kalender penuh. */
    public function rentangPeriode(string $idPerusahaan, string $bulan): array
    {
        $cutoff = $this->pengaturan($idPerusahaan)['tanggal_mulai_cutoff'];
        $acuan  = Carbon::parse($bulan . '-01');

        if ($cutoff <= 1) {
            $mulai   = $acuan->copy()->startOfMonth();
            $selesai = $acuan->copy()->endOfMonth();
        } else {
            $mulai   = $acuan->copy()->subMonthNoOverflow()->day($cutoff);
            $selesai = $acuan->copy()->day($cutoff)->subDay();
        }

        return [
            'tanggal_mulai'   => $mulai->toDateString(),
            'tanggal_selesai' => $selesai->toDateString(),
            'nama'            => sprintf(
                '%d %s – %d %s %d',
                $mulai->day, self::BULAN_ID[$mulai->month],
                $selesai->day, self::BULAN_ID[$selesai->month], $selesai->year,
            ),
        ];
    }

    public function listPeriode(string $idPerusahaan, int $page = 1, int $limit = 10, ?string $search = null, ?string $status = null): array
    {
        $result = $this->repo->paginatePeriode($idPerusahaan, $page, $limit, $search, $status);

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

    public function buatPeriode(string $idPerusahaan, string $bulan): object
    {
        $rentang = $this->rentangPeriode($idPerusahaan, $bulan);

        if ($this->repo->adaPeriodeTumpangTindih($idPerusahaan, $rentang['tanggal_mulai'], $rentang['tanggal_selesai'])) {
            abort(422, 'Sudah ada periode payroll yang tumpang tindih dengan rentang tersebut');
        }

        return $this->repo->createPeriode([
            'id_perusahaan'   => $idPerusahaan,
            'nama'            => $rentang['nama'],
            'tanggal_mulai'   => $rentang['tanggal_mulai'],
            'tanggal_selesai' => $rentang['tanggal_selesai'],
            'status'          => 'draft',
        ]);
    }

    public function detailPeriode(string $id, string $idPerusahaan): array
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);

        return [
            'periode'   => $periode,
            'ringkasan' => $this->repo->ringkasanPeriode($id),
            'slips'     => $this->repo->slipByPeriode($id),
        ];
    }

    public function infoPengajuan(string $id, string $idPerusahaan): ?array
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        return $this->arusKasService->infoPengajuanPeriode($periode->id_periode);
    }

    public function hapusPeriode(string $id, string $idPerusahaan): void
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        if ($periode->status === 'final') {
            abort(422, 'Periode yang sudah final tidak dapat dihapus');
        }

        DB::transaction(function () use ($periode) {
            $this->repo->hapusSlipByPeriode($periode->id_periode);
            $this->repo->deletePeriode($periode);
        });
    }

    // ── Generate slip ────────────────────────────────────────────

    public function generateSlip(string $id, string $idPerusahaan): array
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        if ($periode->status === 'final') {
            abort(422, 'Periode sudah final — tidak dapat generate ulang');
        }

        $pengaturan = $this->pengaturan($idPerusahaan);
        $rekap = $this->absensiService->rekapRentang(
            $idPerusahaan,
            $periode->tanggal_mulai,
            $periode->tanggal_selesai,
            1,
            500,
            null,
            true,
        );
        $tanggalExit = $this->repo->tanggalExitTerakhir($idPerusahaan);

        return DB::transaction(function () use ($periode, $pengaturan, $rekap, $tanggalExit) {
            $this->repo->hapusSlipByPeriode($periode->id_periode);

            $mulai     = Carbon::parse($periode->tanggal_mulai);
            $selesai   = Carbon::parse($periode->tanggal_selesai);
            $totalHari = (int) $mulai->diffInDays($selesai) + 1;

            $dibuat = 0;
            foreach ($rekap['data'] as $r) {
                $exit = $tanggalExit[$r['id_karyawan']] ?? null;
                if (!$r['aktif'] && $exit === null) continue;

                $mulaiEfektif = $r['tanggal_masuk'] !== null
                    ? Carbon::parse($r['tanggal_masuk'])->max($mulai)
                    : $mulai->copy();
                $selesaiEfektif = !$r['aktif']
                    ? Carbon::parse($exit)->min($selesai)
                    : $selesai->copy();

                if ($selesaiEfektif->lessThan($mulaiEfektif)) continue;

                $hariAktif = (int) $mulaiEfektif->diffInDays($selesaiEfektif) + 1;

                $gajiPokokPenuh = (float) $r['gaji_pokok'];
                $gajiPokok  = $gajiPokokPenuh;
                $upahLembur = (float) $r['lembur_rupiah'];
                $tunjangan  = (float) $r['tunjangan_jabatan'];
                $alpha      = (int) $r['alpha'];

                $catatan = null;
                if ($hariAktif < $totalHari) {
                    $gajiPokok = round($gajiPokok * $hariAktif / $totalHari, 2);
                    $tunjangan = round($tunjangan * $hariAktif / $totalHari, 2);

                    $sebab = [];
                    if ($mulaiEfektif->greaterThan($mulai)) $sebab[] = 'masuk ' . $this->tanggalId($mulaiEfektif);
                    if ($selesaiEfektif->lessThan($selesai)) $sebab[] = 'berhenti ' . $this->tanggalId($selesaiEfektif);
                    $catatan = sprintf('Gaji prorata %d/%d hari kalender (%s)', $hariAktif, $totalHari, implode(', ', $sebab));
                }

                $potonganAbsen = round($alpha * ($gajiPokokPenuh / max(1, $pengaturan['hari_kerja_per_bulan'])), 2);

                $persenKes = $r['override_persen_bpjs_kesehatan'] ?? $pengaturan['persen_bpjs_kesehatan'];
                $persenJht = $r['override_persen_bpjs_jht'] ?? $pengaturan['persen_bpjs_jht'];
                $persenJp  = $r['override_persen_bpjs_jp'] ?? $pengaturan['persen_bpjs_jp'];
                $plafonKes = $r['override_plafon_bpjs_kesehatan'] ?? $pengaturan['plafon_gaji_bpjs_kesehatan'];

                $bpjsKes = $r['ikut_bpjs_kesehatan']
                    ? round(min($gajiPokok, $plafonKes) * ($persenKes / 100), 2)
                    : 0.0;
                $bpjsTk = $r['ikut_bpjs_ketenagakerjaan']
                    ? round($gajiPokok * (($persenJht + $persenJp) / 100), 2)
                    : 0.0;

                $bruto = $gajiPokok + $upahLembur + $tunjangan;
                $pph21 = $this->hitungPph21Bulanan($bruto, $r['status_ptkp'], $pengaturan['ptkp_dasar'], $pengaturan['ptkp_tambahan']);

                $totalPotongan = $potonganAbsen + $bpjsKes + $bpjsTk + $pph21;

                $this->repo->createSlip([
                    'id_periode'    => $periode->id_periode,
                    'id_perusahaan' => $periode->id_perusahaan,
                    'id_karyawan'   => $r['id_karyawan'],
                    'gaji_pokok'    => $gajiPokok,
                    'upah_lembur'   => $upahLembur,
                    'tunjangan_lain' => $tunjangan,
                    'menit_lembur'  => (int) $r['lembur_menit'],
                    'jumlah_alpha'  => $alpha,
                    'potongan_absen' => $potonganAbsen,
                    'potongan_bpjs_kesehatan' => $bpjsKes,
                    'potongan_bpjs_tk'        => $bpjsTk,
                    'persen_bpjs_kesehatan'   => $persenKes,
                    'persen_bpjs_jht'         => $persenJht,
                    'persen_bpjs_jp'          => $persenJp,
                    'pph21'         => $pph21,
                    'total_bruto'   => $bruto,
                    'total_potongan' => $totalPotongan,
                    'gaji_bersih'   => $bruto - $totalPotongan,
                    'catatan'       => $catatan,
                ]);
                $dibuat++;
            }

            return ['dibuat' => $dibuat];
        });
    }

    // ── Import Excel ─────────────────────────────────────────────

    private const KOLOM_IMPORT = [
        'NAMA'                => 'nama',
        'PROJECT'             => 'proyek',
        'TYPE TRUCK'          => 'tipe_truck',
        'ABSEN MASUK'         => 'absen_masuk',
        'GAJI POKOK'          => 'gaji_pokok',
        'UANG MAKAN'          => 'uang_makan',
        'TUNJANGAN'           => 'tunjangan_lain',
        'GAJI PRORATE'        => 'gaji_prorate',
        'UANG MAKAN MINGGUAN' => 'uang_makan_mingguan',
        'KASBON'              => 'kasbon',
        'UJ TERPAKAI'         => 'uang_jalan_terpakai',
        'TILANGAN'            => 'tilangan',
        'KETERANGAN'          => 'keterangan',
        'CATATAN'             => 'catatan',
    ];

    /**
     * Import slip gaji dari file Excel (format lembar "GAJI DRIVER"). Header boleh
     * berada di baris mana pun (dicari baris yang memuat NAMA + GAJI POKOK) dan urutan
     * kolom bebas. Karyawan dicocokkan berdasarkan nama ternormalisasi; baris tanpa
     * nama (baris kosong / baris total) dilewati tanpa dihitung. Mode "sebagian masuk
     * + laporan gagal": slip karyawan yang cocok ditimpa, yang belum ada dibuat baru,
     * sedangkan nilai BPJS/PPh21/lembur/potongan absen hasil generate dipertahankan.
     * JUMLAH GAJI dan TOTAL GAJI dari file diabaikan — total dihitung ulang sistem.
     *
     * @return array{berhasil: int, gagal: array<int, array{baris: int, nama: string, alasan: string}>}
     */
    public function importExcel(string $id, string $idPerusahaan, UploadedFile $file): array
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        if ($periode->status === 'final') {
            abort(422, 'Periode sudah final — tidak dapat import');
        }

        $rows = Excel::toArray(new PayrollImport(), $file)[0] ?? [];

        [$barisHeader, $kolom] = $this->cariHeaderImport($rows);
        if ($barisHeader === null) {
            abort(422, 'Kolom NAMA dan GAJI POKOK tidak ditemukan di file — pastikan format sesuai lembar gaji');
        }

        $petaKaryawan = [];
        foreach ($this->repo->semuaKaryawan($idPerusahaan) as $k) {
            $petaKaryawan[$this->normalisasiNama($k->nama_karyawan)][] = $k->id_karyawan;
        }

        $frekuensiNama = [];
        foreach ($rows as $index => $row) {
            if ($index <= $barisHeader) continue;
            $nama = $this->teksSel($row[$kolom['nama']] ?? null);
            if ($nama !== null) {
                $kunci = $this->normalisasiNama($nama);
                $frekuensiNama[$kunci] = ($frekuensiNama[$kunci] ?? 0) + 1;
            }
        }

        $berhasil = 0;
        $gagal = [];

        foreach ($rows as $index => $row) {
            if ($index <= $barisHeader) continue;

            $baris = $index + 1;
            $ambil = fn (string $field) => array_key_exists($field, $kolom) ? ($row[$kolom[$field]] ?? null) : null;

            $nama = $this->teksSel($ambil('nama'));
            if ($nama === null) continue;

            $kunci = $this->normalisasiNama($nama);
            if (($frekuensiNama[$kunci] ?? 0) > 1) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Nama duplikat di dalam file'];
                continue;
            }

            $kandidat = $petaKaryawan[$kunci] ?? [];
            if ($kandidat === []) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Karyawan tidak ditemukan di master'];
                continue;
            }
            if (count($kandidat) > 1) {
                $gagal[] = ['baris' => $baris, 'nama' => $nama, 'alasan' => 'Nama cocok dengan lebih dari satu karyawan — rapikan master dulu'];
                continue;
            }
            $idKaryawan = $kandidat[0];

            $gajiPokokPenuh = $this->angkaSel($ambil('gaji_pokok'));
            $gajiProrate    = $this->angkaSel($ambil('gaji_prorate'));
            $gajiPokok      = $gajiProrate > 0 ? $gajiProrate : $gajiPokokPenuh;

            $catatanParts = array_filter([
                $this->teksSel($ambil('keterangan')),
                $this->teksSel($ambil('catatan')),
                $gajiProrate > 0
                    ? sprintf('Gaji prorata dari Excel (gaji penuh Rp %s)', number_format($gajiPokokPenuh, 0, ',', '.'))
                    : null,
            ]);

            $dataImport = [
                'gaji_pokok'          => $gajiPokok,
                'uang_makan'          => $this->angkaSel($ambil('uang_makan')),
                'tunjangan_lain'      => $this->angkaSel($ambil('tunjangan_lain')),
                'uang_makan_mingguan' => $this->angkaSel($ambil('uang_makan_mingguan')),
                'kasbon'              => $this->angkaSel($ambil('kasbon')),
                'uang_jalan_terpakai' => $this->angkaSel($ambil('uang_jalan_terpakai')),
                'tilangan'            => $this->angkaSel($ambil('tilangan')),
                'proyek'              => $this->teksSel($ambil('proyek')),
                'tipe_truck'          => $this->teksSel($ambil('tipe_truck')),
                'absen_masuk'         => $this->teksSel($ambil('absen_masuk')),
                'catatan'             => $catatanParts !== [] ? implode(' | ', $catatanParts) : null,
            ];

            $slipAda = $this->repo->findSlipByPeriodeKaryawan($periode->id_periode, $idKaryawan);

            $bruto = $dataImport['gaji_pokok'] + (float) ($slipAda->upah_lembur ?? 0)
                + $dataImport['tunjangan_lain'] + $dataImport['uang_makan'];
            $totalPotongan = (float) ($slipAda->potongan_absen ?? 0)
                + (float) ($slipAda->potongan_bpjs_kesehatan ?? 0)
                + (float) ($slipAda->potongan_bpjs_tk ?? 0)
                + (float) ($slipAda->pph21 ?? 0)
                + (float) ($slipAda->potongan_lain ?? 0)
                + $dataImport['uang_makan_mingguan'] + $dataImport['kasbon']
                + $dataImport['uang_jalan_terpakai'] + $dataImport['tilangan'];

            $dataImport['total_bruto']    = $bruto;
            $dataImport['total_potongan'] = $totalPotongan;
            $dataImport['gaji_bersih']    = $bruto - $totalPotongan;

            if ($slipAda !== null) {
                $this->repo->updateSlip($slipAda, $dataImport);
            } else {
                $this->repo->createSlip(array_merge($dataImport, [
                    'id_periode'    => $periode->id_periode,
                    'id_perusahaan' => $periode->id_perusahaan,
                    'id_karyawan'   => $idKaryawan,
                ]));
            }
            $berhasil++;
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    /**
     * Baris template import: seluruh karyawan aktif dengan data identitas terisi;
     * bila periode sudah punya slip, komponen gaji ikut terisi dari slip (round-trip
     * edit: unduh → ubah → upload balik). JUMLAH GAJI & TOTAL GAJI berupa formula.
     *
     * @return array{nama: string, rows: array<int, array<int, mixed>>}
     */
    public function templateImport(string $id, string $idPerusahaan): array
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);

        $slipMap = collect($this->repo->slipByPeriode($periode->id_periode))->keyBy('id_karyawan');

        $rows = [];
        $no = 1;
        foreach ($this->repo->karyawanUntukTemplate($idPerusahaan) as $k) {
            $slip = $slipMap->get($k->id_karyawan);
            $r = count($rows) + 2;

            $rows[] = [
                $no++,
                $k->nama_karyawan,
                $slip->proyek ?? null,
                $slip->tipe_truck ?? null,
                $k->nama_jabatan,
                $k->status_kepegawaian,
                $k->nama_bank,
                $k->nomor_rekening,
                $slip->absen_masuk ?? 'Full',
                $slip !== null ? (float) $slip->gaji_pokok : (float) $k->gaji_pokok,
                (float) ($slip->uang_makan ?? 0),
                (float) ($slip->tunjangan_lain ?? 0),
                "=J{$r}+K{$r}+L{$r}",
                0,
                (float) ($slip->uang_makan_mingguan ?? 0),
                (float) ($slip->kasbon ?? 0),
                (float) ($slip->uang_jalan_terpakai ?? 0),
                (float) ($slip->tilangan ?? 0),
                "=IF(N{$r}>0,N{$r},M{$r})-O{$r}-P{$r}-Q{$r}-R{$r}",
                null,
                $slip->catatan ?? null,
            ];
        }

        return ['nama' => (string) $periode->nama, 'rows' => $rows];
    }

    /** @return array{0: ?int, 1: array<string, int>} indeks baris header + peta field => indeks kolom */
    private function cariHeaderImport(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $kolom = [];
            foreach ($row as $posisi => $sel) {
                $label = $this->teksSel($sel);
                if ($label === null) continue;
                $label = mb_strtoupper($label);
                if (isset(self::KOLOM_IMPORT[$label]) && !isset($kolom[self::KOLOM_IMPORT[$label]])) {
                    $kolom[self::KOLOM_IMPORT[$label]] = $posisi;
                }
            }
            if (isset($kolom['nama'], $kolom['gaji_pokok'])) {
                return [$index, $kolom];
            }
        }

        return [null, []];
    }

    private function normalisasiNama(string $nama): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nama)));
    }

    private function teksSel(mixed $nilai): ?string
    {
        if ($nilai === null) return null;
        $teks = trim((string) preg_replace('/\s+/u', ' ', (string) $nilai));
        return $teks === '' ? null : $teks;
    }

    private function angkaSel(mixed $nilai): float
    {
        if (is_numeric($nilai)) return round((float) $nilai, 2);
        $teks = str_replace(' ', '', trim((string) $nilai));
        return is_numeric($teks) ? round((float) $teks, 2) : 0.0;
    }

    // ── Slip ─────────────────────────────────────────────────────

    public function updateSlip(string $idSlip, string $idPerusahaan, array $data): object
    {
        $slip = $this->repo->findSlipById($idSlip);
        if ($slip === null || $slip->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Slip tidak ditemukan');
        }

        $periode = $this->repo->findPeriodeById($slip->id_periode);
        if ($periode === null || $periode->status === 'final') {
            abort(422, 'Slip pada periode final tidak dapat diubah');
        }

        $nilai = fn (string $k, $default) => array_key_exists($k, $data) ? (float) $data[$k] : (float) $default;

        $tunjangan  = $nilai('tunjangan_lain', $slip->tunjangan_lain);
        $uangMakan  = $nilai('uang_makan', $slip->uang_makan);
        $umMingguan = $nilai('uang_makan_mingguan', $slip->uang_makan_mingguan);
        $kasbon     = $nilai('kasbon', $slip->kasbon);
        $ujTerpakai = $nilai('uang_jalan_terpakai', $slip->uang_jalan_terpakai);
        $tilangan   = $nilai('tilangan', $slip->tilangan);
        $potLain    = $nilai('potongan_lain', $slip->potongan_lain);
        $pph21      = $nilai('pph21', $slip->pph21);

        $bruto = (float) $slip->gaji_pokok + (float) $slip->upah_lembur + $tunjangan + $uangMakan;
        $totalPotongan = (float) $slip->potongan_absen + (float) $slip->potongan_bpjs_kesehatan
            + (float) $slip->potongan_bpjs_tk + $pph21 + $potLain
            + $umMingguan + $kasbon + $ujTerpakai + $tilangan;

        return $this->repo->updateSlip($slip, [
            'tunjangan_lain'       => $tunjangan,
            'keterangan_tunjangan' => $data['keterangan_tunjangan'] ?? $slip->keterangan_tunjangan,
            'uang_makan'           => $uangMakan,
            'uang_makan_mingguan'  => $umMingguan,
            'kasbon'               => $kasbon,
            'uang_jalan_terpakai'  => $ujTerpakai,
            'tilangan'             => $tilangan,
            'potongan_lain'        => $potLain,
            'keterangan_potongan'  => $data['keterangan_potongan'] ?? $slip->keterangan_potongan,
            'pph21'                => $pph21,
            'catatan'              => $data['catatan'] ?? $slip->catatan,
            'total_bruto'          => $bruto,
            'total_potongan'       => $totalPotongan,
            'gaji_bersih'          => $bruto - $totalPotongan,
        ]);
    }

    public function slipUntukPdf(string $idSlip, string $idPerusahaan): array
    {
        $slip = $this->repo->findSlipById($idSlip);
        if ($slip === null || $slip->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Slip tidak ditemukan');
        }

        $periode = $this->repo->findPeriodeById($slip->id_periode);

        return [
            'slip'       => $slip,
            'periode'    => $periode,
            'perusahaan' => $this->repo->getPerusahaan($idPerusahaan),
        ];
    }

    // ── Finalisasi ───────────────────────────────────────────────

    public function finalisasi(string $id, string $idPerusahaan): object
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        if ($periode->status === 'final') {
            abort(422, 'Periode sudah final');
        }
        if ($this->repo->ringkasanPeriode($id)->jumlah_slip === 0) {
            abort(422, 'Belum ada slip — generate dulu sebelum finalisasi');
        }

        return DB::transaction(function () use ($periode) {
            $updated = $this->repo->updatePeriode($periode, [
                'status'            => 'final',
                'difinalisasi_pada' => now(),
                'difinalisasi_oleh' => auth()->id(),
            ]);
            $this->arusKasService->buatPengajuanPayrollOtomatis($updated);
            return $updated;
        });
    }

    public function batalFinalisasi(string $id, string $idPerusahaan): object
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        if ($periode->status !== 'final') {
            abort(422, 'Periode belum final');
        }

        return DB::transaction(function () use ($periode) {
            $this->arusKasService->batalkanPengajuanPayroll($periode->id_periode);
            return $this->repo->updatePeriode($periode, [
                'status'            => 'draft',
                'difinalisasi_pada' => null,
                'difinalisasi_oleh' => null,
            ]);
        });
    }

    // ── PPh21 (metode disetahunkan, tarif progresif UU HPP) ──────

    public function hitungPph21Bulanan(float $brutoBulanan, ?string $statusPtkp, ?float $ptkpDasar = null, ?float $ptkpTambahan = null): float
    {
        $biayaJabatan = min($brutoBulanan * 0.05, 500000);
        $netoTahunan  = ($brutoBulanan - $biayaJabatan) * 12;

        $ptkp = $this->nilaiPtkp($statusPtkp, $ptkpDasar ?? (float) self::PTKP_DASAR, $ptkpTambahan ?? (float) self::PTKP_TAMBAHAN);
        $pkp  = max(0, $netoTahunan - $ptkp);
        $pkp  = floor($pkp / 1000) * 1000;

        if ($pkp <= 0) return 0.0;

        $lapisan = [
            [60000000, 0.05],
            [250000000, 0.15],
            [500000000, 0.25],
            [5000000000, 0.30],
            [PHP_FLOAT_MAX, 0.35],
        ];

        $pajak = 0.0;
        $batasBawah = 0.0;
        foreach ($lapisan as [$batasAtas, $tarif]) {
            if ($pkp <= $batasBawah) break;
            $kenaPajak = min($pkp, $batasAtas) - $batasBawah;
            $pajak += $kenaPajak * $tarif;
            $batasBawah = $batasAtas;
        }

        return round($pajak / 12, 2);
    }

    private function nilaiPtkp(?string $statusPtkp, float $dasar, float $tambahan): float
    {
        if ($statusPtkp === null || !preg_match('/^(TK|K)\/([0-3])$/', $statusPtkp, $m)) {
            return $dasar; // default TK/0
        }

        $kawin     = $m[1] === 'K' ? 1 : 0;
        $tanggungan = (int) $m[2];

        return $dasar + (($kawin + $tanggungan) * $tambahan);
    }

    private function tanggalId(Carbon $tanggal): string
    {
        return $tanggal->day . ' ' . self::BULAN_ID[$tanggal->month] . ' ' . $tanggal->year;
    }

    private function periodeOrFail(string $id, string $idPerusahaan): object
    {
        $periode = $this->repo->findPeriodeById($id);
        if ($periode === null || $periode->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Periode payroll tidak ditemukan');
        }
        return $periode;
    }
}
