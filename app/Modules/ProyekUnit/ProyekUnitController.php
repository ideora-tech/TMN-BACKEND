<?php

declare(strict_types=1);

namespace App\Modules\ProyekUnit;

use App\Helpers\ApiResponse;
use App\Modules\ProyekUnit\Requests\HapusMassalProyekUnitRequest;
use App\Modules\ProyekUnit\Requests\StoreProyekUnitRequest;
use App\Modules\ProyekUnit\Resources\ProyekUnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProyekUnitController extends Controller
{
    public function __construct(private readonly ProyekUnitService $service) {}

    public function index(Request $request, string $idProyek): JsonResponse
    {
        return ApiResponse::success(
            ProyekUnitResource::collection($this->service->list($idProyek, (string) $request->user()->id_perusahaan))
        );
    }

    public function opsi(Request $request, string $idProyek): JsonResponse
    {
        return ApiResponse::success($this->service->opsi($idProyek, (string) $request->user()->id_perusahaan));
    }

    public function store(StoreProyekUnitRequest $request, string $idProyek): JsonResponse
    {
        $baru = $this->service->tambah($idProyek, $request->validated()['unit'], (string) $request->user()->id_perusahaan);
        return ApiResponse::success(ProyekUnitResource::collection($baru), 'Unit berhasil ditambahkan ke proyek', 201);
    }

    public function destroyMassal(HapusMassalProyekUnitRequest $request, string $idProyek): JsonResponse
    {
        $jumlah = $this->service->hapusMassal($idProyek, $request->validated()['ids'], (string) $request->user()->id_perusahaan);
        return ApiResponse::success(['dihapus' => $jumlah], "{$jumlah} unit berhasil dihapus dari proyek");
    }

    public function destroy(Request $request, string $idProyek, string $id): JsonResponse
    {
        $this->service->hapus($idProyek, $id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Unit berhasil dihapus dari proyek');
    }
}
