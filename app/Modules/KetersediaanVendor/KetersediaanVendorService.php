<?php

declare(strict_types=1);

namespace App\Modules\KetersediaanVendor;

use App\Modules\KetersediaanVendor\Contracts\KetersediaanVendorRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class KetersediaanVendorService
{
    public const STATUS_VALID = ['tersedia', 'terjadwal', 'dipakai', 'perawatan'];

    public const SUMBER_VALID = ['aset', 'vendor'];

    private const LABEL_STATUS = [
        'tersedia'  => 'Tersedia',
        'terjadwal' => 'Terjadwal',
        'dipakai'   => 'Sedang Dipakai',
        'perawatan' => 'Dalam Perawatan',
    ];

    private const URUTAN_STATUS = ['tersedia' => 0, 'terjadwal' => 1, 'dipakai' => 2, 'perawatan' => 3];

    public function __construct(private readonly KetersediaanVendorRepositoryInterface $repo) {}

    public function list(string $idPerusahaan, array $filter, int $page, int $limit): array
    {
        $semuaUnit = $this->urutkan($this->repo->listUnit($idPerusahaan, now()->toDateString()));

        $sesuaiPencarian = $this->saring($semuaUnit, Arr::only($filter, ['search', 'id_vendor', 'sumber']));
        $sesuaiJenis = $this->saring($sesuaiPencarian, Arr::only($filter, ['id_jenis_kendaraan']));
        $tampil = $this->saring($sesuaiJenis, Arr::only($filter, ['status']));

        $total = count($tampil);

        return [
            'data' => array_map(fn (object $r) => $this->petaUnit($r), array_slice($tampil, ($page - 1) * $limit, $limit)),
            'meta' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'totalPages'  => max(1, (int) ceil($total / $limit)),
                'ringkasan'   => $this->ringkasan($sesuaiJenis),
                'per_jenis'   => $this->perJenis($semuaUnit, $sesuaiPencarian),
                'opsi_vendor' => $this->opsiVendor($semuaUnit),
            ],
        ];
    }

    public function detail(string $sumber, string $idUnit, string $idPerusahaan): array
    {
        if (!in_array($sumber, self::SUMBER_VALID, true)) {
            abort(404, 'Unit tidak ditemukan');
        }

        $hariIni = now()->toDateString();

        $unit = $this->repo->findUnit($sumber, $idUnit, $idPerusahaan, $hariIni);
        if ($unit === null) {
            abort(404, 'Unit tidak ditemukan');
        }

        $riwayat = array_values(array_filter(
            array_map(fn (object $r) => [
                'id_proyek'        => $r->id_proyek,
                'kode_proyek'      => $r->kode_proyek,
                'nama_proyek'      => $r->nama_proyek,
                'status_proyek'    => $r->status_proyek,
                'nama_klien'       => $r->nama_klien,
                'hari_pakai'       => (int) $r->hari_pakai,
                'hari_terjadwal'   => (int) $r->hari_terjadwal,
                'pertama_dipakai'  => $this->tanggal($r->pertama_dipakai),
                'terakhir_dipakai' => $this->tanggal($r->terakhir_dipakai),
            ], $this->repo->riwayatProyek($sumber, $idUnit, $idPerusahaan, $hariIni)),
            fn (array $r) => $r['hari_pakai'] + $r['hari_terjadwal'] > 0,
        ));

        usort($riwayat, fn (array $a, array $b) => strcmp((string) ($b['terakhir_dipakai'] ?? $b['pertama_dipakai'] ?? ''), (string) ($a['terakhir_dipakai'] ?? $a['pertama_dipakai'] ?? '')));

        return array_merge($this->petaUnit($unit), ['riwayat_proyek' => $riwayat]);
    }

    public function dataExport(string $idPerusahaan, array $filter): array
    {
        $hariIni = now()->toDateString();
        $unit = $this->saring($this->urutkan($this->repo->listUnit($idPerusahaan, $hariIni)), $filter);

        return [
            'unit'    => array_map(fn (object $r) => $this->petaUnit($r), $unit),
            'tanggal' => $hariIni,
            'status'  => isset($filter['status']) ? (self::LABEL_STATUS[$filter['status']] ?? null) : null,
        ];
    }

    /**
     * @param object[] $unit
     * @return object[]
     */
    private function urutkan(array $unit): array
    {
        usort($unit, function (object $a, object $b): int {
            $urutanStatus = self::URUTAN_STATUS[$a->status_ketersediaan] <=> self::URUTAN_STATUS[$b->status_ketersediaan];
            if ($urutanStatus !== 0) {
                return $urutanStatus;
            }

            $terakhirA = $this->tanggal($a->terakhir_dipakai) ?? '';
            $terakhirB = $this->tanggal($b->terakhir_dipakai) ?? '';
            if ($terakhirA !== $terakhirB) {
                return $terakhirB <=> $terakhirA;
            }

            return strcasecmp($a->nopol, $b->nopol);
        });

        return $unit;
    }

    /**
     * @param object[] $unit
     * @return object[]
     */
    private function saring(array $unit, array $filter): array
    {
        $cari = isset($filter['search']) ? mb_strtolower((string) $filter['search']) : null;

        return array_values(array_filter($unit, function (object $r) use ($filter, $cari) {
            if (isset($filter['status']) && $r->status_ketersediaan !== $filter['status']) {
                return false;
            }
            if (isset($filter['sumber']) && $r->sumber !== $filter['sumber']) {
                return false;
            }
            if (isset($filter['id_vendor']) && $r->id_vendor !== $filter['id_vendor']) {
                return false;
            }
            if (isset($filter['id_jenis_kendaraan']) && $r->id_jenis_kendaraan !== $filter['id_jenis_kendaraan']) {
                return false;
            }
            if ($cari === null) {
                return true;
            }

            foreach ([$r->nopol, $r->merk, $r->model, $r->jenis, $r->nama_jenis_kendaraan, $r->nama_vendor] as $kolom) {
                if ($kolom !== null && str_contains(mb_strtolower((string) $kolom), $cari)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function petaUnit(object $r): array
    {
        $terjadwalSampai = $this->tanggal($r->terjadwal_sampai);
        $dipakaiTanpaPenugasanHariIni = $r->status_ketersediaan === 'dipakai' && (int) $r->pakai_hari_ini === 0;

        return [
            'sumber'               => $r->sumber,
            'id_unit'              => $r->id_unit,
            'nopol'                => $r->nopol,
            'merk'                 => $r->merk,
            'model'                => $r->model,
            'jenis'                => $r->jenis,
            'id_jenis_kendaraan'   => $r->id_jenis_kendaraan,
            'nama_jenis_kendaraan' => $r->nama_jenis_kendaraan ?? $r->jenis,
            'kapasitas'            => $r->kapasitas ?? $this->kapasitasKg($r->kapasitas_muatan_kg),
            'tahun'                => $r->tahun !== null ? (int) $r->tahun : null,
            'masa_berlaku_stnk'    => $this->tanggal($r->masa_berlaku_stnk),
            'masa_berlaku_kir'     => $this->tanggal($r->masa_berlaku_kir),
            'id_vendor'            => $r->id_vendor,
            'nama_vendor'          => $r->nama_vendor,
            'telepon_vendor'       => $r->telepon_vendor,
            'pic_vendor'           => $r->pic_vendor,
            'status_ketersediaan'  => $r->status_ketersediaan,
            'jumlah_proyek'        => (int) $r->jumlah_proyek,
            'hari_pakai'           => (int) $r->hari_pakai,
            'terakhir_dipakai'     => $this->tanggal($r->terakhir_dipakai),
            'proyek_terakhir'      => $this->petaProyek($r->id_proyek_terakhir, $r->kode_proyek_terakhir, $r->nama_proyek_terakhir, $r->nama_klien_terakhir),
            'proyek_jadwal'        => $dipakaiTanpaPenugasanHariIni
                ? null
                : $this->petaProyek($r->id_proyek_jadwal, $r->kode_proyek_jadwal, $r->nama_proyek_jadwal, $r->nama_klien_jadwal),
            'jadwal_berikutnya'    => $this->tanggal($r->jadwal_berikutnya),
            'terjadwal_sampai'     => $terjadwalSampai,
            'perkiraan_bebas'      => $terjadwalSampai !== null ? Carbon::parse($terjadwalSampai)->addDay()->toDateString() : null,
            'perawatan_berikutnya' => $this->tanggal($r->perawatan_berikutnya),
        ];
    }

    private function kapasitasKg(mixed $kg): ?string
    {
        return $kg !== null ? number_format((int) $kg, 0, ',', '.') . ' kg' : null;
    }

    private function petaProyek(?string $id, ?string $kode, ?string $nama, ?string $klien): ?array
    {
        if ($id === null) {
            return null;
        }

        return ['id_proyek' => $id, 'kode_proyek' => $kode, 'nama_proyek' => $nama, 'nama_klien' => $klien];
    }

    private function tanggal(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return substr((string) $nilai, 0, 10);
    }

    /** @param object[] $unit */
    private function ringkasan(array $unit): array
    {
        $hasil = ['total' => count($unit), 'tersedia' => 0, 'terjadwal' => 0, 'dipakai' => 0, 'perawatan' => 0];
        foreach ($unit as $r) {
            $hasil[$r->status_ketersediaan]++;
        }

        return $hasil;
    }

    /** @param object[] $semua */
    private function opsiVendor(array $semua): array
    {
        $vendor = [];
        foreach ($semua as $r) {
            if ($r->id_vendor === null) {
                continue;
            }
            $vendor[$r->id_vendor] ??= ['id_vendor' => $r->id_vendor, 'nama_vendor' => $r->nama_vendor];
        }

        $hasil = array_values($vendor);
        usort($hasil, fn (array $a, array $b) => strcasecmp($a['nama_vendor'], $b['nama_vendor']));

        return $hasil;
    }

    /**
     * @param object[] $semua
     * @param object[] $terhitung
     */
    private function perJenis(array $semua, array $terhitung): array
    {
        $grup = [];
        foreach ($semua as $r) {
            $grup[$this->kunciJenis($r)] ??= [
                'id_jenis_kendaraan' => $r->id_jenis_kendaraan,
                'nama_jenis'         => $r->nama_jenis_kendaraan ?? $r->jenis ?? 'Tanpa jenis',
                'total'              => 0,
                'tersedia'           => 0,
                'terjadwal'          => 0,
                'dipakai'            => 0,
                'perawatan'          => 0,
                'tersedia_aset'      => 0,
                'tersedia_vendor'    => 0,
            ];
        }

        foreach ($terhitung as $r) {
            $kunci = $this->kunciJenis($r);
            $grup[$kunci]['total']++;
            $grup[$kunci][$r->status_ketersediaan]++;
            if ($r->status_ketersediaan === 'tersedia') {
                $grup[$kunci]['tersedia_' . $r->sumber]++;
            }
        }

        $hasil = array_values($grup);
        usort($hasil, fn (array $a, array $b) => [$b['tersedia'], $b['total'], $a['nama_jenis']] <=> [$a['tersedia'], $a['total'], $b['nama_jenis']]);

        return $hasil;
    }

    private function kunciJenis(object $r): string
    {
        return $r->id_jenis_kendaraan ?? 'nama:' . mb_strtolower((string) ($r->nama_jenis_kendaraan ?? $r->jenis ?? 'Tanpa jenis'));
    }
}
