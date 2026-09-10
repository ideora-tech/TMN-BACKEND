<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Helpers\ApiResponse;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\UbahPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $service) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->service->login($request->username, $request->password);
        return ApiResponse::success($result, 'Login berhasil');
    }

    public function logout(): JsonResponse
    {
        $this->service->logout(auth()->user());
        return ApiResponse::success(null, 'Logout berhasil');
    }

    public function ubahPassword(UbahPasswordRequest $request): JsonResponse
    {
        $this->service->ubahPassword($request->user(), $request->password_lama, $request->password_baru);
        return ApiResponse::success(null, 'Password berhasil diubah');
    }

    public function me(): JsonResponse
    {
        return ApiResponse::success(auth()->user(), 'Data pengguna');
    }
}
