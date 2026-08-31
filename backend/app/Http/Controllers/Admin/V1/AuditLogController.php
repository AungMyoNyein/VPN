<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('id');

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->get('target_type'));
        }

        if ($request->filled('target_id')) {
            $query->where('target_id', $request->get('target_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->get('action'));
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 15)));
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return ApiResponse::success(['audit_log' => $auditLog]);
    }
}
