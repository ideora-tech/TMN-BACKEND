<?php

declare(strict_types=1);

namespace App\Modules\Penugasan;

use App\Modules\Penugasan\Contracts\PenugasanRepositoryInterface;
use App\Modules\Proyek\Contracts\ProyekRepositoryInterface;
use App\Modules\Trip\Contracts\TripRepositoryInterface;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SinkronPenugasanProyekService
{
    private const MAKS_HARI_PER_PENUGASAN = 60;

    public function __construct(
        private readonly PenugasanRepositoryInterface $repo,
        private readonly PenugasanService $penugasanService,
        private readonly ProyekRepositoryInterface $proyekRepo,
        private readonly TripRepositoryInterface $tripRepo,
    ) {}

    public function pratinjau(string $idProyek, string $idPerusahaan, array $periode): array
    {
        $this->proyekMilikOrFail($idProyek, $idPerusahaan);
        $rencana = $this->rencana($idProyek, $periode);

        return [
            'tambah' => array_map(fn ($t) => [
                'nopol'       => $t['nopol'],
                'nama_supir'  => $t['nama_supir'],
                'dari'        => $t['dari'],
                'sampai'      => $t['sampai'],
                'jumlah_hari' => $t['jumlah_hari'],
            ], $rencana['tambah']),
            'total_tambah'   => array_sum(array_column($rencana['tambah'], 'jumlah_hari')),
            'hapus'          => $this->ringkasPerUnit($rencana['hapus']),
            'total_hapus'    => count($rencana['hapus']),
            'terkunci'       => $this->ringkasPerUnit($rencana['terkunci']),
            'total_terkunci' => count($rencana['terkunci']),
        ];
    }

    public function jalankan(string $idProyek, string $idPerusahaan, array $periode): array
    {
        $proyek = $this->proyekMilikOrFail($idProyek, $idPerusahaan);
        if ($this->tanggal($proyek->tanggal_mulai) !== $periode['baru_mulai']
            || $this->tanggal($proyek->tanggal_selesai) !== ($periode['baru_selesai'] ?? null)) {
            abort(422, 'Periode proyek sudah berubah — muat ulang halaman lalu coba lagi');
        }

        $rencana = $this->rencana($idProyek, $periode);
        $gagal = [];

        $dihapus = 0;
        foreach ($rencana['hapus'] as $h) {
            try {
                $this->penugasanService->delete($h['id_penugasan'], $idPerusahaan);
                $dihapus++;
            } catch (HttpException $e) {
                $gagal[] = ['unit' => $h['nopol'], 'tanggal' => $h['tanggal'], 'alasan' => $e->getMessage()];
            }
        }

        $dibuat = 0;
        foreach ($rencana['tambah'] as $t) {
            $titikDrop = $this->repo->titikDropDetailUntukBanyak([$t['id_penugasan_acuan']])[$t['id_penugasan_acuan']] ?? [];

            foreach ($this->pecahRentang($t['dari'], $t['sampai']) as [$dari, $sampai]) {
                try {
                    $hasil = $this->penugasanService->assignHarian([
                        'tanggal'          => $dari,
                        'tanggal_sampai'   => $sampai,
                        'id_proyek'        => $idProyek,
                        'id_rute'          => $t['id_rute'],
                        'id_armada'        => $t['id_armada'],
                        'id_armada_vendor' => $t['id_armada_vendor'],
                        'id_supir'         => $t['id_supir'],
                        'id_supir_vendor'  => $t['id_supir_vendor'],
                        'keterangan'       => $t['keterangan'],
                        'titik_drop'       => $titikDrop !== [] ? $titikDrop : null,
                    ], $idPerusahaan);
                    $dibuat += $hasil['sukses'];
                    foreach ($hasil['gagal'] as $g) {
                        $gagal[] = ['unit' => $t['nopol']] + $g;
                    }
                } catch (HttpException $e) {
                    $gagal[] = ['unit' => $t['nopol'], 'tanggal' => "{$dari} s/d {$sampai}", 'alasan' => $e->getMessage()];
                }
            }
        }

        return [
            'dibuat'   => $dibuat,
            'dihapus'  => $dihapus,
            'terkunci' => count($rencana['terkunci']),
            'gagal'    => $gagal,
        ];
    }

    /** Hanya unit yang berjalan sampai ujung periode lama yang diperpanjang — unit yang berhenti lebih awal dianggap sengaja. */
    private function rencana(string $idProyek, array $periode): array
    {
        $lamaMulai = $periode['lama_mulai'];
        $lamaAkhir = $periode['lama_selesai'] ?? $lamaMulai;
        $baruMulai = $periode['baru_mulai'];
        $baruAkhir = $periode['baru_selesai'] ?? $baruMulai;

        $baris = collect($this->repo->listUntukSinkronProyek($idProyek))
            ->each(fn ($r) => $r->tanggal = $this->tanggal($r->tanggal_tugas));

        $tambah = [];
        $perUnit = $baris
            ->filter(fn ($r) => $r->id_armada !== null || $r->id_armada_vendor !== null)
            ->groupBy(fn ($r) => ($r->id_armada ?? 'V' . $r->id_armada_vendor) . '|' . $r->id_rute);

        foreach ($perUnit as $rows) {
            $pertama  = $rows->first();
            $terakhir = $rows->last();

            if ($baruAkhir > $lamaAkhir && $terakhir->tanggal === $lamaAkhir) {
                $tambah[] = $this->itemTambah($terakhir, $this->geser($lamaAkhir, 1), $baruAkhir);
            }
            if ($baruMulai < $lamaMulai && $pertama->tanggal === $lamaMulai) {
                $tambah[] = $this->itemTambah($pertama, $baruMulai, $this->geser($lamaMulai, -1));
            }
        }

        $hapus    = [];
        $terkunci = [];
        foreach ($baris as $r) {
            if ($r->tanggal >= $baruMulai && $r->tanggal <= $baruAkhir) {
                continue;
            }

            $item = ['id_penugasan' => $r->id_penugasan, 'nopol' => $r->nopol, 'nama_supir' => $r->nama_supir, 'tanggal' => $r->tanggal];
            $adaJejak = $r->status === 'selesai'
                || $this->tripRepo->adaTripNonFinalUntukPenugasan($r->id_penugasan)
                || $this->tripRepo->adaTripSelesaiUntukPenugasan($r->id_penugasan);

            if ($adaJejak) {
                $terkunci[] = $item;
            } else {
                $hapus[] = $item;
            }
        }

        return ['tambah' => $tambah, 'hapus' => $hapus, 'terkunci' => $terkunci];
    }

    private function itemTambah(object $acuan, string $dari, string $sampai): array
    {
        return [
            'id_penugasan_acuan' => $acuan->id_penugasan,
            'id_armada'          => $acuan->id_armada,
            'id_armada_vendor'   => $acuan->id_armada_vendor,
            'id_supir'           => $acuan->id_supir,
            'id_supir_vendor'    => $acuan->id_supir_vendor,
            'id_rute'            => $acuan->id_rute,
            'keterangan'         => $acuan->keterangan,
            'nopol'              => $acuan->nopol,
            'nama_supir'         => $acuan->nama_supir,
            'dari'               => $dari,
            'sampai'             => $sampai,
            'jumlah_hari'        => (int) Carbon::parse($dari)->diffInDays(Carbon::parse($sampai)) + 1,
        ];
    }

    private function ringkasPerUnit(array $items): array
    {
        return collect($items)
            ->groupBy(fn ($i) => ($i['nopol'] ?? '-') . '|' . ($i['nama_supir'] ?? '-'))
            ->map(fn ($g) => [
                'nopol'      => $g->first()['nopol'],
                'nama_supir' => $g->first()['nama_supir'],
                'jumlah'     => $g->count(),
                'dari'       => $g->min('tanggal'),
                'sampai'     => $g->max('tanggal'),
            ])
            ->values()
            ->all();
    }

    private function pecahRentang(string $dari, string $sampai): array
    {
        $potongan = [];
        $awal  = Carbon::parse($dari);
        $akhir = Carbon::parse($sampai);

        while ($awal->lte($akhir)) {
            $ujung = $awal->copy()->addDays(self::MAKS_HARI_PER_PENUGASAN - 1)->min($akhir);
            $potongan[] = [$awal->toDateString(), $ujung->toDateString()];
            $awal = $ujung->copy()->addDay();
        }

        return $potongan;
    }

    private function geser(string $tanggal, int $hari): string
    {
        return Carbon::parse($tanggal)->addDays($hari)->toDateString();
    }

    private function tanggal(mixed $nilai): ?string
    {
        return $nilai === null || $nilai === '' ? null : Carbon::parse((string) $nilai)->toDateString();
    }

    private function proyekMilikOrFail(string $idProyek, string $idPerusahaan): object
    {
        $proyek = $this->proyekRepo->findById($idProyek);
        if ($proyek === null || (string) $proyek->id_perusahaan !== $idPerusahaan) {
            abort(404, 'Proyek tidak ditemukan');
        }
        return $proyek;
    }
}
