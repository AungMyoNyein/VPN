<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Canonical API envelope helpers (ADR-0005).
 */
final class ApiResponse
{
    public static function success(mixed $data = [], array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'request_id' => request()->attributes->get('request_id'),
            ], $meta),
        ], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
                'request_id' => request()->attributes->get('request_id'),
            ], $extra),
        ], $status);
    }
}
