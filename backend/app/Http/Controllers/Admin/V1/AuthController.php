<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\Auth\LoginRequest;
use App\Models\AdminUser;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $admin = AdminUser::query()->where('email', $request->validated('email'))->first();

        if ($admin === null || ! Hash::check($request->validated('password'), $admin->password)) {
            return ApiResponse::error('UNAUTHENTICATED', 'Invalid credentials.', 401);
        }

        if (! $admin->isActive()) {
            return ApiResponse::error('FORBIDDEN', 'Admin account is disabled.', 403);
        }

        $token = $admin->createToken('admin-api')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'admin' => $this->formatAdmin($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($plainToken = $request->bearerToken()) {
            PersonalAccessToken::findToken($plainToken)?->delete();
        }

        return ApiResponse::success(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $admin->load('roles.permissions');

        return ApiResponse::success([
            'admin' => $this->formatAdmin($admin),
            'permissions' => $admin->roles
                ->flatMap(fn ($role) => $role->permissions)
                ->pluck('code')
                ->unique()
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAdmin(AdminUser $admin): array
    {
        $admin->loadMissing('roles');

        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => $admin->status->value,
            'roles' => $admin->roles->pluck('code'),
        ];
    }
}
