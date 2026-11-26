<?php

declare(strict_types=1);

namespace App\Modules\KategoriPengajuan;

use App\Helpers\ApiResponse;
use App\Modules\KategoriPengajuan\Requests\StoreKategoriPengajuanRequest;
use App\Modules\KategoriPengajuan\Requests\UpdateKategoriPengajuanRequest;
use App\Modules\KategoriPengajuan\Resources\KategoriPengajuanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KategoriPengajuanController extends Controller
{
    public function __construct(private readonly KategoriPengajuanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $aktif = $request->get('aktif');
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $aktif === null || $aktif === '' ? null : (bool) (int) $aktif,
        );

        return ApiResponse::paginated(KategoriPengajuanResource::collection($result['data']), $result['meta']);
    }

    /** Daftar ringkas untuk dropdown & label (form pemasukan, laporan). */
    public function opsi(Request $request): JsonResponse
    {
        $data = $this->service->opsi((string) $request->user()->id_perusahaan, $request->boolean('aktif'));

        return ApiResponse::success(['kategori' => KategoriPengajuanResource::collection($data)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KategoriPengajuanResource($record));
    }

    public function store(StoreKategoriPengajuanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KategoriPengajuanResource($record), 'Kategori pengajuan berhasil dibuat', 201);
    }

    public function update(UpdateKategoriPengajuanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KategoriPengajuanResource($record), 'Kategori pengajuan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Kategori pengajuan berhasil dihapus');
    }
}
