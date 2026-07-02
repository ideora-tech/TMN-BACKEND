<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $code = 200): JsonResponse
    {
        return response()->json([
            'success'   => true,
            'message'   => $message,
            'data'      => $data,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }

    public static function error(string $message, mixed $errors = null, int $code = 400): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        return response()->json($body, $code);
    }

    public static function paginated(mixed $data, array $meta): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ]);
    }
}
