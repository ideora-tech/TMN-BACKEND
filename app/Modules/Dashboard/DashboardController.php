<?php
namespace App\Modules\Dashboard;
use App\Helpers\ApiResponse;
use App\Modules\Armada\ArmadaModel;
use App\Modules\Faktur\FakturModel;
use App\Modules\Proyek\ProyekModel;
use App\Modules\Trip\TripModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller {
    public function stats(Request $request): JsonResponse {
        $id = $request->user()->id_perusahaan;
        $bulanIni = now()->startOfMonth();

        return ApiResponse::success([
            'tripBerjalan'        => TripModel::active()
                ->join('jadwal_keberangkatan as jk', 'trip.id_jadwal', '=', 'jk.id_jadwal')
                ->join('penugasan as p', 'jk.id_penugasan', '=', 'p.id_penugasan')
                ->join('proyek as pr', 'p.id_proyek', '=', 'pr.id_proyek')
                ->where('pr.id_perusahaan', $id)
                ->where('trip.status', 'berjalan')
                ->whereNull('jk.dihapus_pada')
                ->whereNull('p.dihapus_pada')
                ->whereNull('pr.dihapus_pada')
                ->count(),
            'armadaAktif'         => ArmadaModel::active()->where('id_perusahaan', $id)->where('status','aktif')->count(),
            'proyekBerjalan'      => ProyekModel::active()->where('id_perusahaan', $id)->where('status','aktif')->count(),
            'fakturDraft'         => FakturModel::active()->where('id_perusahaan', $id)->where('status','draft')->count(),
            'pendapatanBulanIni'  => (int) FakturModel::active()->where('id_perusahaan', $id)->where('status','lunas')->where('dibuat_pada','>=',$bulanIni)->sum('total'),
            'piutangBeredar'      => FakturModel::active()->where('id_perusahaan', $id)->where('status','terkirim')->count(),
        ]);
    }
}