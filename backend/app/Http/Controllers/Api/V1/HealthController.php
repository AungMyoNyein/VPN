<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'service' => 'vpn-api',
            'phase' => 0,
            'api_version' => 'v1',
        ]);
    }
}
