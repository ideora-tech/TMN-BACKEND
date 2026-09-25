<?php

declare(strict_types=1);

namespace App\Modules\Barang;

use App\Helpers\ApiResponse;
use App\Modules\Barang\Requests\BuatCepatBarangRequest;
use App\Modules\Barang\Requests\PemakaianBarangRequest;
use App\Modules\Barang\Requests\PenyesuaianBarangRequest;
use App\Modules\Barang\Requests\StoreBarangRequest;
use App\Modules\Barang\Requests\StoreKategoriBarangRequest;
use App\Modules\Barang\Requests\UpdateBarangRequest;
use App\Modules\Barang\Resources\BarangMutasiResource;
use App\Modules\Barang\Resources\BarangResource;
use App\Modules\Barang\Resources\KategoriBarangResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BarangController extends Controller
{
    public function __construct(private readonly BarangService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->list(
            (string) $request->user()->id_perusahaan,
            (int) $request->get('page', 1),
            (int) $request->get('limit', 10),
            $request->get('search'),
            $request->get('id_kategori_barang'),
            $request->boolean('stok_menipis'),
        );
        return ApiResponse::paginated(BarangResource::collection($result['data']), $result['meta']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(new BarangResource($this->service->findOrFail($id, (string) $request->user()->id_perusahaan)));
    }

    public function store(StoreBarangRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new BarangResource($record), 'Barang berhasil ditambahkan', 201);
    }

    public function buatCepat(BuatCepatBarangRequest $request): JsonResponse
    {
        $record = $this->service->buatCepat($request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new BarangResource($record), 'Barang berhasil didaftarkan', 201);
    }

    public function update(UpdateBarangRequest $request, string $id): JsonResponse
    {
        $record = $this->service->update($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new BarangResource($record), 'Barang berhasil diperbarui');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Barang berhasil dihapus');
    }

    public function listMutasi(Request $request, string $id): JsonResponse
    {
        $result = $this->service->listMutasi($id, (string) $request->user()->id_perusahaan, (int) $request->get('page', 1), (int) $request->get('limit', 10));
        return ApiResponse::paginated(BarangMutasiResource::collection($result['data']), $result['meta']);
    }

    public function pemakaian(PemakaianBarangRequest $request, string $id): JsonResponse
    {
        $record = $this->service->pemakaian($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new BarangResource($record), 'Pemakaian barang tercatat, stok diperbarui');
    }

    public function penyesuaian(PenyesuaianBarangRequest $request, string $id): JsonResponse
    {
        $record = $this->service->penyesuaian($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new BarangResource($record), 'Penyesuaian stok tersimpan');
    }

    public function indexKategori(Request $request): JsonResponse
    {
        $data = $this->service->listKategori((string) $request->user()->id_perusahaan, $request->boolean('hanya_aktif'));
        return ApiResponse::success(KategoriBarangResource::collection(collect($data)));
    }

    public function storeKategori(StoreKategoriBarangRequest $request): JsonResponse
    {
        $record = $this->service->createKategori($request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KategoriBarangResource($record), 'Kategori berhasil ditambahkan', 201);
    }

    public function updateKategori(StoreKategoriBarangRequest $request, string $id): JsonResponse
    {
        $record = $this->service->updateKategori($id, $request->validated(), (string) $request->user()->id_perusahaan);
        return ApiResponse::success(new KategoriBarangResource($record), 'Kategori berhasil diperbarui');
    }

    public function destroyKategori(Request $request, string $id): JsonResponse
    {
        $this->service->deleteKategori($id, (string) $request->user()->id_perusahaan);
        return ApiResponse::success(null, 'Kategori berhasil dihapus');
    }
}
