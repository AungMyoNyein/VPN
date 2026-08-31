<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivationKey;
use App\Models\AdminUser;
use App\Services\ActivationKeys\ActivationKeyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivationKeyController extends Controller
{
    public function __construct(
        private readonly ActivationKeyService $activationKeyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ActivationKey::query()->with('customer')->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->get('customer_id'));
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 15)));
    }

    public function show(ActivationKey $activationKey): JsonResponse
    {
        $activationKey->load('customer');

        return ApiResponse::success(['activation_key' => $activationKey]);
    }

    public function revoke(ActivationKey $activationKey, Request $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'activation_key' => $this->activationKeyService->revoke($activationKey, $actor),
        ]);
    }

    public function suspend(ActivationKey $activationKey, Request $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'activation_key' => $this->activationKeyService->suspend($activationKey, $actor),
        ]);
    }
}
