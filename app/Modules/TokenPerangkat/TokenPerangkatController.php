<?php

declare(strict_types=1);

namespace App\Modules\TokenPerangkat;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\TokenPerangkat\Requests\StoreTokenPerangkatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenPerangkatController extends Controller
{
    public function __construct(private readonly TokenPerangkatService $service) {}

    public function store(StoreTokenPerangkatRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->service->daftar(
            (string) $request->user()->id_pengguna,
            $validated['token'],
            $validated['platform'] ?? 'android',
        );

        return ApiResponse::success(null, 'Token perangkat terdaftar');
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $this->service->hapus((string) $request->user()->id_pengguna, $validated['token']);

        return ApiResponse::success(null, 'Token perangkat dihapus');
    }
}
