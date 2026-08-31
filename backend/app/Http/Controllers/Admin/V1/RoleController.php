<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return ApiResponse::success(['role' => $role]);
    }
}
