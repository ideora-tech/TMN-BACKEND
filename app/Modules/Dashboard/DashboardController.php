<?php
namespace App\Modules\Dashboard;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller {
    public function __construct(private readonly DashboardService $service) {}

    public function stats(Request $request): JsonResponse {
        $id = $request->user()->id_perusahaan;

        $stats = $this->service->getStats($id);

        // Angka finansial hanya untuk role keuangan/manajemen — role operasional
        // (supir, dispatcher, sales) tidak boleh membaca ringkasan pendapatan/piutang.
        $kodePeran = (string) $request->user()->kode_peran;
        if (!in_array($kodePeran, ['SUPERADMIN', 'ADMIN', 'MANAGER', 'KEUANGAN'], true)) {
            $stats['fakturDraft'] = null;
            $stats['pendapatanBulanIni'] = null;
            $stats['piutangBeredar'] = null;
        }

        return ApiResponse::success($stats);
    }
}
