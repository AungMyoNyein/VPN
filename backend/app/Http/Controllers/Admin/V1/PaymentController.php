<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\Payments\StorePaymentRequest;
use App\Models\AdminUser;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['customer', 'subscription'])->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->get('customer_id'));
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 15)));
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'payment' => $this->paymentService->create($request->validated(), $actor),
        ], status: 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['customer', 'subscription']);

        return ApiResponse::success(['payment' => $payment]);
    }
}
