<?php

declare(strict_types=1);

namespace App\Modules\PermintaanVendor;

use App\Helpers\ApiResponse;
use App\Modules\PermintaanVendor\Requests\BatalPermintaanVendorRequest;
use App\Modules\PermintaanVendor\Requests\StorePermintaanVendorRequest;
use App\Modules\PermintaanVendor\Requests\TolakPermintaanVendorRequest;
use App\Modules\PermintaanVendor\Requests\UpdatePermintaanVendorRequest;
use App\Modules\PermintaanVendor\Resources\PermintaanVendorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PermintaanVendorController extends Controller
{
    public function __construct(private readonly PermintaanVendorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('status'),
            $request->get('id_penawaran'),
        );

        return ApiResponse::paginated(
            PermintaanVendorResource::collection($result['data']),
            $result['meta']
        );
    }

    public function jumlahAktif(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->jumlahAktif((string) $request->user()->id_perusahaan));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PermintaanVendorResource($record));
    }

    public function store(StorePermintaanVendorRequest $request): JsonResponse
    {
        $record = $this->service->create(array_merge(
            $request->validated(),
            ['id_perusahaan' => (string) $request->user()->id_perusahaan],
        ));

        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan vendor berhasil dibuat', 201);
    }

    public function update(UpdatePermintaanVendorRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan vendor berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Permintaan vendor berhasil dihapus');
    }

    public function ajukanApproval(Request $request, string $id): JsonResponse
    {
        $record = $this->service->ajukanApproval(
            $id,
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan diajukan untuk approval');
    }

    public function proses(Request $request, string $id): JsonResponse
    {
        $record = $this->service->proses(
            $id,
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan vendor sedang diproses');
    }

    public function tolak(TolakPermintaanVendorRequest $request, string $id): JsonResponse
    {
        $record = $this->service->tolak(
            $id,
            (string) $request->validated('alasan'),
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan vendor ditolak Pengadaan');
    }

    public function revisi(Request $request, string $id): JsonResponse
    {
        $record = $this->service->revisi(
            $id,
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan vendor dikembalikan ke draft untuk direvisi');
    }

    public function batal(BatalPermintaanVendorRequest $request, string $id): JsonResponse
    {
        $record = $this->service->batal(
            $id,
            (string) $request->validated('alasan'),
            (string) $request->user()->id_pengguna,
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success(new PermintaanVendorResource($record), 'Permintaan vendor dibatalkan');
    }
}
