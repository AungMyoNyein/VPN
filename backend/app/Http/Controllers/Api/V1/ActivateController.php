<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ActivateRequest;
use App\Services\Activation\ActivationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ActivateController extends Controller
{
    public function __construct(
        private readonly ActivationService $activationService,
    ) {}

    public function store(ActivateRequest $request): JsonResponse
    {
        $result = $this->activationService->activate($request->validated());

        if (! $result['ok']) {
            return ApiResponse::error($result['code'], $result['message'], $result['status']);
        }

        return ApiResponse::success($result['data']);
    }
}
