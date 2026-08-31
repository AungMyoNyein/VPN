<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\Plans\StorePlanRequest;
use App\Http\Requests\Admin\V1\Plans\UpdatePlanRequest;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Plan::query()->orderBy('name');

        if ($request->has('active')) {
            $query->where('active', filter_var($request->get('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return ApiResponse::success(['plans' => $query->get()]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = Plan::query()->create($request->validated());

        return ApiResponse::success(['plan' => $plan], status: 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return ApiResponse::success(['plan' => $plan]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return ApiResponse::success(['plan' => $plan->fresh()]);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        $plan->delete();

        return ApiResponse::success(['message' => 'Plan deleted.']);
    }
}
