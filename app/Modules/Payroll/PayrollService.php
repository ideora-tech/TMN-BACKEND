<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Modules\Absensi\AbsensiService;
use App\Modules\Payroll\Contracts\PayrollRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        );

        return DB::transaction(function () use ($periode, $pengaturan, $rekap) {
            $this->repo->hapusSlipByPeriode($periode->id_periode);

            $dibuat = 0;
            foreach ($rekap['data'] as $r) {
                $gajiPokok  = (float) $r['gaji_pokok'];
                $upahLembur = (float) $r['lembur_rupiah'];
                $tunjangan  = (float) $r['tunjangan_jabatan'];
                $alpha      = (int) $r['alpha'];

                $potonganAbsen = round($alpha * ($gajiPokok / max(1, $pengaturan['hari_kerja_per_bulan'])), 2);

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
                $pph21 = $this->hitungPph21Bulanan($bruto, $r['status_ptkp']);

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
                ]);
                $dibuat++;
            }

            return ['dibuat' => $dibuat];
        });
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

        $tunjangan = $nilai('tunjangan_lain', $slip->tunjangan_lain);
        $potLain   = $nilai('potongan_lain', $slip->potongan_lain);
        $pph21     = $nilai('pph21', $slip->pph21);

        $bruto = (float) $slip->gaji_pokok + (float) $slip->upah_lembur + $tunjangan;
        $totalPotongan = (float) $slip->potongan_absen + (float) $slip->potongan_bpjs_kesehatan
            + (float) $slip->potongan_bpjs_tk + $pph21 + $potLain;

        return $this->repo->updateSlip($slip, [
            'tunjangan_lain'       => $tunjangan,
            'keterangan_tunjangan' => $data['keterangan_tunjangan'] ?? $slip->keterangan_tunjangan,
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

        return $this->repo->updatePeriode($periode, [
            'status'            => 'final',
            'difinalisasi_pada' => now(),
            'difinalisasi_oleh' => auth()->id(),
        ]);
    }

    public function batalFinalisasi(string $id, string $idPerusahaan): object
    {
        $periode = $this->periodeOrFail($id, $idPerusahaan);
        if ($periode->status !== 'final') {
            abort(422, 'Periode belum final');
        }

        return $this->repo->updatePeriode($periode, [
            'status'            => 'draft',
            'difinalisasi_pada' => null,
            'difinalisasi_oleh' => null,
        ]);
    }

    // ── PPh21 (metode disetahunkan, tarif progresif UU HPP) ──────

    public function hitungPph21Bulanan(float $brutoBulanan, ?string $statusPtkp): float
    {
        $biayaJabatan = min($brutoBulanan * 0.05, 500000);
        $netoTahunan  = ($brutoBulanan - $biayaJabatan) * 12;

        $ptkp = $this->nilaiPtkp($statusPtkp);
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

    private function nilaiPtkp(?string $statusPtkp): float
    {
        if ($statusPtkp === null || !preg_match('/^(TK|K)\/([0-3])$/', $statusPtkp, $m)) {
            return self::PTKP_DASAR; // default TK/0
        }

        $kawin     = $m[1] === 'K' ? 1 : 0;
        $tanggungan = (int) $m[2];

        return self::PTKP_DASAR + (($kawin + $tanggungan) * self::PTKP_TAMBAHAN);
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
