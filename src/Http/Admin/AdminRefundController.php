<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\CreateRefundData;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\ConcurrentRefundException;
use Glueful\Extensions\Commerce\Orders\Refunds\IdempotencyConflictException;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundOutcomeUnknownException;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminRefundController
{
    use ResolvesActor;

    private const MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    public function __construct(
        private ApplicationContext $context,
        private ?OrderRepository $orders = null,
        private ?RefundRepository $refundsRepo = null,
        private ?RefundService $refunds = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->refundsRepo ??= app($context, RefundRepository::class);
        $this->refunds ??= app($context, RefundService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'Issue an order refund', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Refund recorded')]
    #[ApiResponse(404, description: 'Order not found')]
    #[ApiResponse(409, description: 'Idempotency conflict or concurrent refund')]
    #[ApiResponse(422, description: 'Validation failed')]
    #[ApiResponse(503, description: 'Refund outcome unknown')]
    public function store(CreateRefundData $input, Request $request, string $uuid): Response
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            return Response::validation([
                'idempotency_key' => 'A non-empty Idempotency-Key header (max 128 chars) is required.',
            ]);
        }

        try {
            $lines = $this->validateLines($input->lines);
        } catch (RefundValidationException $e) {
            return Response::validation(['lines' => $e->getMessage()]);
        }

        try {
            $refund = $this->refunds->issue(
                $this->context,
                $uuid,
                new RefundInput($input->amount, $input->reason, $lines, $input->restock),
                $key,
                $this->actorUuid($request)
            );

            return Response::success($refund, 'Refund recorded');
        } catch (IdempotencyConflictException | ConcurrentRefundException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (RefundValidationException $e) {
            return Response::validation(['refund' => $e->getMessage()]);
        } catch (RefundOutcomeUnknownException $e) {
            return Response::error($e->getMessage(), 503);
        }
    }

    #[ApiOperation(summary: 'List refunds for an order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Refunds retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function index(Request $request, string $uuid): Response
    {
        // Tenant-scoped 404 guard first (non-revealing), before any refund lookup.
        $tenant = $this->tenants->tenantUuid($this->context);
        if ($this->orders->findByUuid($this->context, $tenant, $uuid) === null) {
            throw new NotFoundException('Resource not found.');
        }

        return Response::success(
            $this->refundsRepo->listForOrder($this->context, $tenant, $uuid),
            'Refunds retrieved'
        );
    }

    /**
     * Shape-checks each raw line element before it reaches {@see RefundInput}: a
     * malformed element (missing/incorrectly-typed key) becomes a 422 via
     * {@see RefundValidationException} rather than a TypeError deep inside
     * RefundService. Nested-DTO support for arbitrary request arrays is pending;
     * validating here is a deliberate, temporary substitute.
     *
     * @return list<array{order_line_uuid:string,quantity:int,amount:int}>
     */
    private function validateLines(?array $lines): array
    {
        if ($lines === null) {
            return [];
        }

        $result = [];
        foreach ($lines as $index => $line) {
            if (
                !is_array($line)
                || !isset($line['order_line_uuid'], $line['quantity'], $line['amount'])
                || !is_string($line['order_line_uuid'])
                || !is_int($line['quantity'])
                || !is_int($line['amount'])
            ) {
                throw new RefundValidationException(
                    "lines.{$index}: must include order_line_uuid (string), quantity (int), and amount (int)."
                );
            }

            $result[] = [
                'order_line_uuid' => $line['order_line_uuid'],
                'quantity' => $line['quantity'],
                'amount' => $line['amount'],
            ];
        }

        return $result;
    }
}
