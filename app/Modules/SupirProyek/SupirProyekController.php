<?php

declare(strict_types=1);

namespace App\Modules\SupirProyek;

use App\Helpers\ApiResponse;
use App\Modules\SupirProyek\Requests\StoreSupirProyekRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class SupirProyekController extends Controller
{
    public function __construct(private readonly SupirProyekService $service) {}

    public function index(Request $request): JsonResponse
    {
        Validator::make($request->query(), [
            'id_proyek' => ['required', 'string'],
        ])->validate();

        $rows = $this->service->list(
            (string) $request->get('id_proyek'),
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success($rows);
    }

    public function store(StoreSupirProyekRequest $request): JsonResponse
    {
        $data = $request->validated();

        $hasil = $this->service->tambahBatch(
            $data['id_proyek'],
            $data['supir'],
            (string) $request->user()->id_perusahaan,
        );

        return ApiResponse::success($hasil, 'Supir proyek diproses');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->hapus($id, (string) $request->user()->id_perusahaan);

        return ApiResponse::success(null, 'Supir proyek berhasil dihapus');
    }
}
