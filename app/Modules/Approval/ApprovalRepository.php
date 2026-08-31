<?php

declare(strict_types=1);

namespace App\Modules\Approval;

use App\Modules\Approval\Contracts\ApprovalRepositoryInterface;
use App\Support\RecordHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApprovalRepository implements ApprovalRepositoryInterface
{
    private const KODE_REFERENSI_PENGELUARAN = [
        'pengajuan_pengeluaran',
        'uang_jalan',
        'legalitas',
        'perawatan',
        'sparepart',
        'penggajian',
        'pembelian_aset',
        'pembayaran_pinjaman',
        'lainnya',
        'persetujuan_transfer',
    ];

    public function listEventType(string $idPerusahaan): Collection
    {
        return ApprovalEventTypeModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->orderBy('nama')
            ->get();
    }

    public function findEventTypeOrFail(string $id, string $idPerusahaan): ApprovalEventTypeModel
    {
        $record = ApprovalEventTypeModel::active()
            ->where('id_event_type', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();

        if ($record === null) {
            abort(404, 'Event type approval tidak ditemukan');
        }

        return $record;
    }

    public function findEventTypeAktifByKode(string $kode, string $idPerusahaan): ?ApprovalEventTypeModel
    {
        return ApprovalEventTypeModel::active()
            ->where('kode', $kode)
            ->where('id_perusahaan', $idPerusahaan)
            ->where('aktif', 1)
            ->first();
    }

    public function findEventTypeByKode(string $kode, string $idPerusahaan): ?ApprovalEventTypeModel
    {
        return ApprovalEventTypeModel::active()
            ->where('kode', $kode)
            ->where('id_perusahaan', $idPerusahaan)
            ->first();
    }

    public function createEventType(array $data): ApprovalEventTypeModel
    {
        return ApprovalEventTypeModel::create($data);
    }

    public function updateEventType(ApprovalEventTypeModel $model, array $data): ApprovalEventTypeModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function adaRiwayatPengajuanUntukEventType(string $idEventType): bool
    {
        return DB::table('approval_pengajuan')
            ->where('id_event_type', $idEventType)
            ->exists();
    }

    public function deleteEventType(string $idEventType): void
    {
        DB::table('approval_event_type')
            ->where('id_event_type', $idEventType)
            ->update(RecordHelper::stampDelete());
    }

    public function listConfigApprover(string $idEventType, string $idPerusahaan): array
    {
        return DB::table('approval_config_approver as ac')
            ->leftJoin('jabatan as j', function ($join) use ($idPerusahaan) {
                $join->on('j.id_jabatan', '=', 'ac.id_jabatan')
                    ->where('j.id_perusahaan', $idPerusahaan);
            })
            ->leftJoin('pengguna as p', function ($join) use ($idPerusahaan) {
                $join->on('p.id_pengguna', '=', 'ac.id_pengguna')
                    ->where('p.id_perusahaan', $idPerusahaan);
            })
            ->where('ac.id_event_type', $idEventType)
            ->whereNull('ac.dihapus_pada')
            ->orderBy('ac.dibuat_pada')
            ->selectRaw('ac.*, COALESCE(j.nama_jabatan, p.username) as nama')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function adaConfigApproverAktif(string $idEventType, string $tipe, ?string $idJabatan, ?string $idPengguna): bool
    {
        return DB::table('approval_config_approver')
            ->where('id_event_type', $idEventType)
            ->where('tipe', $tipe)
            ->when($tipe === 'jabatan', fn ($q) => $q->where('id_jabatan', $idJabatan))
            ->when($tipe === 'pengguna', fn ($q) => $q->where('id_pengguna', $idPengguna))
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function insertConfigApprover(array $data): string
    {
        $stamped = RecordHelper::stampCreate($data, 'id_config');
        DB::table('approval_config_approver')->insert($stamped);
        return $stamped['id_config'];
    }

    public function deleteConfigApprover(string $id, string $idEventType): bool
    {
        $jumlah = DB::table('approval_config_approver')
            ->where('id_config', $id)
            ->where('id_event_type', $idEventType)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());

        return $jumlah > 0;
    }

    public function deleteConfigApproverByEventType(string $idEventType): void
    {
        DB::table('approval_config_approver')
            ->where('id_event_type', $idEventType)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
    }

    public function resolvePinned(string $idEventType, string $idPerusahaan): array
    {
        $dariPengguna = DB::table('approval_config_approver as ac')
            ->join('pengguna as p', 'p.id_pengguna', '=', 'ac.id_pengguna')
            ->where('ac.id_event_type', $idEventType)
            ->where('ac.tipe', 'pengguna')
            ->whereNull('ac.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna');

        $dariJabatan = DB::table('approval_config_approver as ac')
            ->join('karyawan as k', 'k.id_jabatan', '=', 'ac.id_jabatan')
            ->join('pengguna as p', 'p.id_karyawan', '=', 'k.id_karyawan')
            ->where('ac.id_event_type', $idEventType)
            ->where('ac.tipe', 'jabatan')
            ->whereNull('ac.dihapus_pada')
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.aktif', 1)
            ->whereNull('k.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna');

        return $dariPengguna->merge($dariJabatan)->unique()->values()->all();
    }

    public function cariJabatanPengguna(string $idPengguna, string $idPerusahaan): ?object
    {
        return DB::table('pengguna as p')
            ->join('karyawan as k', 'k.id_karyawan', '=', 'p.id_karyawan')
            ->where('p.id_pengguna', $idPengguna)
            ->where('p.id_perusahaan', $idPerusahaan)
            ->whereNull('k.dihapus_pada')
            ->select('k.id_jabatan')
            ->first();
    }

    public function cariJabatanInduk(string $idJabatan): ?object
    {
        return DB::table('jabatan')
            ->where('id_jabatan', $idJabatan)
            ->whereNull('dihapus_pada')
            ->select('id_jabatan', 'id_jabatan_induk')
            ->first();
    }

    public function jabatanMilikPerusahaan(string $idJabatan, string $idPerusahaan): bool
    {
        return DB::table('jabatan')
            ->where('id_jabatan', $idJabatan)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function penggunaMilikPerusahaan(string $idPengguna, string $idPerusahaan): bool
    {
        return DB::table('pengguna')
            ->where('id_pengguna', $idPengguna)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereNull('dihapus_pada')
            ->exists();
    }

    public function cariUserAktifPemegangJabatan(string $idJabatan, string $idPerusahaan): array
    {
        return DB::table('karyawan as k')
            ->join('pengguna as p', 'p.id_karyawan', '=', 'k.id_karyawan')
            ->where('k.id_jabatan', $idJabatan)
            ->where('k.id_perusahaan', $idPerusahaan)
            ->where('k.aktif', 1)
            ->whereNull('k.dihapus_pada')
            ->where('p.id_perusahaan', $idPerusahaan)
            ->where('p.aktif', 1)
            ->whereNull('p.dihapus_pada')
            ->pluck('p.id_pengguna')
            ->all();
    }

    public function createPengajuan(array $data): ApprovalPengajuanModel
    {
        return ApprovalPengajuanModel::create($data);
    }

    public function insertKeputusanRows(string $idApproval, array $idPenggunaList): void
    {
        $now = now();
        $rows = array_map(fn (string $idPengguna) => RecordHelper::stampCreate([
            'id_approval' => $idApproval,
            'id_pengguna' => $idPengguna,
            'status'      => 'menunggu',
        ], 'id_keputusan'), $idPenggunaList);

        DB::table('approval_keputusan')->insert($rows);
    }

    public function findPengajuanForUpdate(string $id, string $idPerusahaan): ?ApprovalPengajuanModel
    {
        return ApprovalPengajuanModel::active()
            ->where('id_approval', $id)
            ->where('id_perusahaan', $idPerusahaan)
            ->lockForUpdate()
            ->first();
    }

    public function findKeputusanMenunggu(string $idApproval, string $idPengguna): ?object
    {
        return DB::table('approval_keputusan')
            ->where('id_approval', $idApproval)
            ->where('id_pengguna', $idPengguna)
            ->where('status', 'menunggu')
            ->whereNull('dihapus_pada')
            ->first();
    }

    public function updateKeputusanJikaMenunggu(string $idKeputusan, array $data): int
    {
        return DB::table('approval_keputusan')
            ->where('id_keputusan', $idKeputusan)
            ->where('status', 'menunggu')
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampUpdate($data));
    }

    public function hitungKeputusanBelumSetuju(string $idApproval): int
    {
        return DB::table('approval_keputusan')
            ->where('id_approval', $idApproval)
            ->where('status', '!=', 'disetujui')
            ->whereNull('dihapus_pada')
            ->count();
    }

    public function updatePengajuan(ApprovalPengajuanModel $model, array $data): ApprovalPengajuanModel
    {
        $model->update($data);
        return $model->fresh();
    }

    public function findPengajuanAktifUntukReferensi(string $idEventType, string $idReferensi): ?ApprovalPengajuanModel
    {
        return ApprovalPengajuanModel::active()
            ->where('id_event_type', $idEventType)
            ->where('id_referensi', $idReferensi)
            ->where('status', '!=', 'dibatalkan')
            ->orderByDesc('dibuat_pada')
            ->first();
    }

    public function findPengajuanAktifUntukReferensiForUpdate(string $idEventType, string $idReferensi, string $idPerusahaan): ?ApprovalPengajuanModel
    {
        return ApprovalPengajuanModel::active()
            ->where('id_event_type', $idEventType)
            ->where('id_referensi', $idReferensi)
            ->where('id_perusahaan', $idPerusahaan)
            ->whereIn('status', ['menunggu', 'disetujui'])
            ->lockForUpdate()
            ->orderByDesc('dibuat_pada')
            ->first();
    }

    public function progressApproval(string $idApproval): array
    {
        $disetujui = DB::table('approval_keputusan')
            ->where('id_approval', $idApproval)->where('status', 'disetujui')->whereNull('dihapus_pada')->count();
        $total = DB::table('approval_keputusan')
            ->where('id_approval', $idApproval)->whereNull('dihapus_pada')->count();

        return ['disetujui' => $disetujui, 'total' => $total];
    }

    public function statusUntukReferensi(string $kode, string $idReferensi, string $idPerusahaan): ?array
    {
        $eventType = $this->findEventTypeByKode($kode, $idPerusahaan);
        if ($eventType === null) {
            return null;
        }

        $pengajuan = ApprovalPengajuanModel::active()
            ->where('id_event_type', $eventType->id_event_type)
            ->where('id_referensi', $idReferensi)
            ->where('id_perusahaan', $idPerusahaan)
            ->orderByDesc('dibuat_pada')
            ->first();
        if ($pengajuan === null) {
            return null;
        }

        $namaPengaju = DB::table('pengguna')->where('id_pengguna', $pengajuan->id_pengguna_pengaju)->value('username');
        $keputusan = DB::table('approval_keputusan as ak')
            ->leftJoin('pengguna as p', 'p.id_pengguna', '=', 'ak.id_pengguna')
            ->where('ak.id_approval', $pengajuan->id_approval)
            ->whereNull('ak.dihapus_pada')
            ->orderBy('ak.dibuat_pada')
            ->get(['ak.status', 'ak.catatan', 'ak.waktu_aksi', 'p.username']);

        return [
            'id_approval'   => $pengajuan->id_approval,
            'status'        => $pengajuan->status,
            'nominal'       => $pengajuan->nominal !== null ? (float) $pengajuan->nominal : null,
            'diajukan_oleh' => $namaPengaju,
            'diajukan_pada' => $pengajuan->dibuat_pada,
            'progress'      => $this->progressApproval($pengajuan->id_approval),
            'approver'      => $keputusan->map(static fn (object $r) => [
                'nama'       => $r->username ?? '-',
                'status'     => $r->status,
                'catatan'    => $r->catatan,
                'waktu_aksi' => $r->waktu_aksi,
            ])->values()->all(),
        ];
    }

    public function listMenungguApprovalSaya(string $idPengguna, string $idPerusahaan): Collection
    {
        return ApprovalPengajuanModel::active()
            ->where('id_perusahaan', $idPerusahaan)
            ->where('status', 'menunggu')
            ->whereExists(fn ($q) => $q->from('approval_keputusan as ak')
                ->whereColumn('ak.id_approval', 'approval_pengajuan.id_approval')
                ->where('ak.id_pengguna', $idPengguna)
                ->where('ak.status', 'menunggu')
                ->whereNull('ak.dihapus_pada'))
            ->orderByDesc('dibuat_pada')
            ->get()
            ->each(function (ApprovalPengajuanModel $pengajuan) {
                $et = DB::table('approval_event_type')
                    ->where('id_event_type', $pengajuan->id_event_type)
                    ->first(['kode', 'nama']);
                $namaPengaju = DB::table('pengguna')->where('id_pengguna', $pengajuan->id_pengguna_pengaju)->value('username');
                $pengajuan->setAttribute('nama_event_type', $et->nama ?? '-');
                $pengajuan->setAttribute('nama_pengaju', $namaPengaju);

                $ringkasan = $this->ringkasanReferensi($et->kode ?? '', $pengajuan->id_referensi);
                $pengajuan->setAttribute('kode_event_type', $et->kode ?? null);
                $pengajuan->setAttribute('nomor_referensi', $ringkasan['nomor']);
                $pengajuan->setAttribute('keterangan_referensi', $ringkasan['keterangan']);
                $pengajuan->setAttribute('pihak_referensi', $ringkasan['pihak']);
            });
    }

    public function listRiwayatApprovalSaya(string $idPengguna, string $idPerusahaan): Collection
    {
        return DB::table('approval_keputusan as ak')
            ->join('approval_pengajuan as ap', 'ap.id_approval', '=', 'ak.id_approval')
            ->where('ak.id_pengguna', $idPengguna)
            ->whereIn('ak.status', ['disetujui', 'ditolak'])
            ->whereNull('ak.dihapus_pada')
            ->where('ap.id_perusahaan', $idPerusahaan)
            ->orderByDesc('ak.waktu_aksi')
            ->select([
                'ap.id_approval',
                'ap.id_event_type',
                'ap.id_referensi',
                'ap.id_pengguna_pengaju',
                'ap.nominal',
                'ap.status as status_pengajuan',
                'ap.dibuat_pada as diajukan_pada',
                'ak.status as keputusan_saya',
                'ak.catatan as catatan_saya',
                'ak.waktu_aksi as diputuskan_pada',
            ])
            ->get()
            ->each(function ($row) {
                $et = DB::table('approval_event_type')
                    ->where('id_event_type', $row->id_event_type)
                    ->first(['kode', 'nama']);
                $namaPengaju = DB::table('pengguna')->where('id_pengguna', $row->id_pengguna_pengaju)->value('username');
                $row->nama_event_type = $et->nama ?? '-';
                $row->kode_event_type = $et->kode ?? null;
                $row->nama_pengaju = $namaPengaju;

                $ringkasan = $this->ringkasanReferensi($et->kode ?? '', $row->id_referensi);
                $row->nomor_referensi = $ringkasan['nomor'];
                $row->keterangan_referensi = $ringkasan['keterangan'];
                $row->pihak_referensi = $ringkasan['pihak'];
            });
    }

    private function ringkasanReferensi(string $kode, string $idReferensi): array
    {
        $kosong = ['nomor' => null, 'keterangan' => null, 'pihak' => null];

        if (in_array($kode, self::KODE_REFERENSI_PENGELUARAN, true)) {
            $row = DB::table('pengajuan_pengeluaran')
                ->where('id_pengajuan', $idReferensi)
                ->first(['nomor_pengajuan', 'kategori', 'penerima', 'keterangan']);
            if ($row === null) {
                return $kosong;
            }
            return [
                'nomor'      => $row->nomor_pengajuan,
                'keterangan' => $row->keterangan !== null && $row->keterangan !== ''
                    ? $row->keterangan
                    : str_replace('_', ' ', (string) $row->kategori),
                'pihak'      => $row->penerima,
            ];
        }

        switch ($kode) {
            case 'penawaran':
                $row = DB::table('penawaran as p')
                    ->leftJoin('klien as k', 'k.id_klien', '=', 'p.id_klien')
                    ->where('p.id_penawaran', $idReferensi)
                    ->first(['p.nomor_penawaran', 'p.judul', 'k.nama_klien']);
                if ($row === null) {
                    return $kosong;
                }
                return [
                    'nomor'      => $row->nomor_penawaran,
                    'keterangan' => $row->judul,
                    'pihak'      => $row->nama_klien,
                ];

            case 'faktur':
                $row = DB::table('faktur as f')
                    ->leftJoin('klien as k', 'k.id_klien', '=', 'f.id_klien')
                    ->leftJoin('proyek as pr', 'pr.id_proyek', '=', 'f.id_proyek')
                    ->where('f.id_faktur', $idReferensi)
                    ->first(['f.nomor_faktur', 'pr.nama_proyek', 'k.nama_klien']);
                if ($row === null) {
                    return $kosong;
                }
                return [
                    'nomor'      => $row->nomor_faktur,
                    'keterangan' => $row->nama_proyek,
                    'pihak'      => $row->nama_klien,
                ];

            case 'invoice_vendor':
                $row = DB::table('invoice_vendor as iv')
                    ->leftJoin('vendor as v', 'v.id_vendor', '=', 'iv.id_vendor')
                    ->where('iv.id_invoice_vendor', $idReferensi)
                    ->first(['iv.nomor_invoice', 'iv.keterangan', 'v.nama_vendor']);
                if ($row === null) {
                    return $kosong;
                }
                return [
                    'nomor'      => $row->nomor_invoice,
                    'keterangan' => $row->keterangan,
                    'pihak'      => $row->nama_vendor,
                ];
        }

        return $kosong;
    }

    public function voidKeputusanUntukApproval(string $idApproval): void
    {
        DB::table('approval_keputusan')
            ->where('id_approval', $idApproval)
            ->whereNull('dihapus_pada')
            ->update(RecordHelper::stampDelete());
    }
}
