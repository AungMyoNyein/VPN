<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\AdminUsers\StoreAdminUserRequest;
use App\Http\Requests\Admin\V1\AdminUsers\UpdateAdminUserRequest;
use App\Models\AdminUser;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'admin_users' => AdminUser::query()->with('roles')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roleIds = $data['role_ids'] ?? [];
        unset($data['role_ids']);

        $admin = AdminUser::query()->create($data);
        $admin->roles()->sync($roleIds);

        return ApiResponse::success(['admin_user' => $admin->load('roles')], status: 201);
    }

    public function show(AdminUser $adminUser): JsonResponse
    {
        $adminUser->load('roles');

        return ApiResponse::success(['admin_user' => $adminUser]);
    }

    public function update(UpdateAdminUserRequest $request, AdminUser $adminUser): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        if (isset($data['role_ids'])) {
            $adminUser->roles()->sync($data['role_ids']);
            unset($data['role_ids']);
        }

        $adminUser->update($data);

        return ApiResponse::success(['admin_user' => $adminUser->fresh()->load('roles')]);
    }

    public function destroy(AdminUser $adminUser, Request $request): JsonResponse
    {
        if ($request->user()?->id === $adminUser->id) {
            return ApiResponse::error('FORBIDDEN', 'Cannot delete your own account.', 403);
        }

        $adminUser->tokens()->delete();
        $adminUser->delete();

        return ApiResponse::success(['message' => 'Admin user deleted.']);
    }
}
