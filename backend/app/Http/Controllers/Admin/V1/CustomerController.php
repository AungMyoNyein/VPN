<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V1\Customers\ChangeCustomerStatusRequest;
use App\Http\Requests\Admin\V1\Customers\StoreCustomerRequest;
use App\Http\Requests\Admin\V1\Customers\UpdateCustomerRequest;
use App\Http\Requests\Admin\V1\Customers\CustomerRenewRequest;
use App\Http\Requests\Admin\V1\Customers\CustomerPaymentRequest;
use App\Http\Requests\Admin\V1\Customers\GenerateActivationKeyRequest;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\ActivationKeys\ActivationKeyService;
use App\Services\Customers\CustomerService;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly SubscriptionService $subscriptionService,
        private readonly ActivationKeyService $activationKeyService,
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customers = $this->customerService->list(
            $request->only(['search', 'status']),
            (int) $request->get('per_page', 15),
        );

        return ApiResponse::success($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $result = $this->customerService->create($request->validated(), $actor);

        return ApiResponse::success($result, status: 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load([
            'subscriptions.plan',
            'devices.activeCredential',
            'devices.vpnPeers.node.location',
            'activationKeys',
            'payments',
        ]);

        $devices = $customer->devices->map(function ($device) {
            $payload = $device->toArray();
            unset($payload['device_token_hash'], $payload['credentials']);
            $payload['has_active_credential'] = $device->activeCredential !== null;
            $payload['credential_issued_at'] = $device->activeCredential?->issued_at;
            $payload['credential_last_used_at'] = $device->activeCredential?->last_used_at;

            $activePeer = $device->vpnPeers->first(fn ($p) => $p->status === \App\Enums\PeerStatus::Active);
            $payload['active_vpn_peer'] = $activePeer ? [
                'peer_code' => $activePeer->peer_code,
                'assigned_ip' => $activePeer->assigned_ip,
                'node_name' => $activePeer->node?->name,
                'location' => $activePeer->node?->location?->display_name,
                'status' => $activePeer->status->value,
            ] : null;

            return $payload;
        });

        $vpnPeers = $customer->devices->flatMap->vpnPeers->map(function ($peer) {
            return [
                'id' => $peer->id,
                'peer_code' => $peer->peer_code,
                'device_name' => $peer->device?->device_name,
                'platform' => $peer->device?->platform?->value,
                'node_name' => $peer->node?->name,
                'location' => $peer->node?->location?->display_name,
                'assigned_ip' => $peer->assigned_ip,
                'status' => $peer->status->value,
                'provisioned_at' => $peer->provisioned_at,
                'revoked_at' => $peer->revoked_at,
            ];
        });

        $customerPayload = $customer->toArray();
        $customerPayload['devices'] = $devices;
        $customerPayload['vpn_peers'] = $vpnPeers;
        unset($customerPayload['device_token_hash']);

        return ApiResponse::success(['customer' => $customerPayload]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'customer' => $this->customerService->update($customer, $request->validated(), $actor),
        ]);
    }

    public function changeStatus(ChangeCustomerStatusRequest $request, Customer $customer): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'customer' => $this->customerService->changeStatus(
                $customer,
                $request->enum('status', \App\Enums\CustomerStatus::class),
                $actor,
            ),
        ]);
    }

    public function renew(CustomerRenewRequest $request, Customer $customer): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'subscription' => $this->subscriptionService->renewCustomer(
                $customer,
                $request->validated(),
                $actor,
            ),
        ]);
    }

    public function generateKey(GenerateActivationKeyRequest $request, Customer $customer): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();
        $result = $this->activationKeyService->generate($customer, $actor, $request->validated());

        return ApiResponse::success([
            'activation_key' => $result['key'],
            'plaintext_key' => $result['plaintext_key'],
        ], status: 201);
    }

    public function addPayment(CustomerPaymentRequest $request, Customer $customer): JsonResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user();

        return ApiResponse::success([
            'payment' => $this->paymentService->create(
                array_merge($request->validated(), ['customer_id' => $customer->id]),
                $actor,
            ),
        ], status: 201);
    }
}
