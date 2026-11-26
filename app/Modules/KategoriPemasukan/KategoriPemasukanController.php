<?php

declare(strict_types=1);

namespace App\Modules\KategoriPemasukan;

use App\Helpers\ApiResponse;
use App\Modules\KategoriPemasukan\Requests\StoreKategoriPemasukanRequest;
use App\Modules\KategoriPemasukan\Requests\UpdateKategoriPemasukanRequest;
use App\Modules\KategoriPemasukan\Resources\KategoriPemasukanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class KategoriPemasukanController extends Controller
{
    public function __construct(private readonly KategoriPemasukanService $service) {}

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

        return ApiResponse::paginated(KategoriPemasukanResource::collection($result['data']), $result['meta']);
    }

    /** Daftar ringkas untuk dropdown & label (form pemasukan, laporan). */
    public function opsi(Request $request): JsonResponse
    {
        $data = $this->service->opsi((string) $request->user()->id_perusahaan, $request->boolean('aktif'));

        return ApiResponse::success([
            'kategori' => KategoriPemasukanResource::collection($data),
            'pos_psak' => collect(KategoriPemasukanService::POS_PSAK)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $record = $this->service->findOrFail($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KategoriPemasukanResource($record));
    }

    public function store(StoreKategoriPemasukanRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KategoriPemasukanResource($record), 'Kategori pemasukan berhasil dibuat', 201);
    }

    public function update(UpdateKategoriPemasukanRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);

        return ApiResponse::success(new KategoriPemasukanResource($record), 'Kategori pemasukan berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Kategori pemasukan berhasil dihapus');
    }
}
