<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\Locations\StoreLocationRequest;
use App\Http\Requests\Admin\V1\Locations\UpdateLocationRequest;
use App\Models\Location;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'locations' => Location::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = Location::query()->create($request->validated());

        return ApiResponse::success(['location' => $location], status: 201);
    }

    public function show(Location $location): JsonResponse
    {
        $location->load('vpnNodes');

        return ApiResponse::success(['location' => $location]);
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $location->update($request->validated());

        return ApiResponse::success(['location' => $location->fresh()]);
    }

    public function destroy(Location $location): JsonResponse
    {
        $location->delete();

        return ApiResponse::success(['message' => 'Location deleted.']);
    }
}
