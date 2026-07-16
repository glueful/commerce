<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Discounts\DiscountRedeemedException;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateDiscountData;
use Glueful\Extensions\Commerce\Http\DTOs\DiscountListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateDiscountData;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminDiscountController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?DiscountRepository $discounts = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?DiscountService $discountService = null,
    ) {
        $this->discounts ??= app($context, DiscountRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->discountService ??= app($context, DiscountService::class);
    }

    #[ApiOperation(summary: 'List discounts', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Discounts retrieved')]
    public function index(DiscountListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->discountService->list(
            $this->context,
            array_filter(
                ['status' => $query->status, 'q' => $query->q],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Discounts retrieved');
    }

    #[ApiOperation(summary: 'Get a discount', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Discount retrieved')]
    #[ApiResponse(404, description: 'Discount not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->discountService->show($this->context, $uuid), 'Discount retrieved');
    }

    #[ApiOperation(summary: 'Create a discount', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Discount created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateDiscountData $input, Request $request): Response
    {
        try {
            $row = $this->validated($this->createPayload($input));
            $row['uuid'] = Utils::generateNanoID();
            $row['tenant_uuid'] = $this->tenants->tenantUuid($this->context);
            $row['usage_count'] = 0;
            $row['status'] = $row['status'] ?? 'active';
            $this->discounts->insert($this->context, $row);

            return Response::created($row, 'Discount created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a discount', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateDiscountData::class)]
    #[ApiResponse(200, description: 'Discount updated')]
    #[ApiResponse(404, description: 'Discount not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $changes = $this->validated($this->input($request), partial: true);
            $discount = $this->discountService->update($this->context, $uuid, $changes);

            return Response::success($discount, 'Discount updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a discount', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Discount deleted')]
    #[ApiResponse(404, description: 'Discount not found')]
    #[ApiResponse(409, description: 'Discount has been redeemed and cannot be deleted')]
    public function destroy(Request $request, string $uuid): Response
    {
        try {
            $this->discountService->delete($this->context, $uuid);

            return Response::noContent();
        } catch (DiscountRedeemedException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validated(array $input, bool $partial = false): array
    {
        if (!$partial && (!isset($input['code']) || trim((string) $input['code']) === '')) {
            throw ValidationException::forField('code', 'Code is required.');
        }
        if (!$partial && !isset($input['type'])) {
            throw ValidationException::forField('type', 'Type is required.');
        }
        if (isset($input['type']) && !in_array($input['type'], ['percentage', 'fixed'], true)) {
            throw ValidationException::forField('type', 'Type must be percentage or fixed.');
        }
        foreach (['value', 'min_subtotal', 'usage_limit'] as $field) {
            if (isset($input[$field]) && (int) $input[$field] < 0) {
                throw ValidationException::forField($field, "{$field} must be non-negative.");
            }
        }
        if (
            isset($input['starts_at'], $input['ends_at'])
            && is_string($input['starts_at'])
            && is_string($input['ends_at'])
            && $input['starts_at'] > $input['ends_at']
        ) {
            throw ValidationException::forField('ends_at', 'End date must be after start date.');
        }

        return $input;
    }

    /** @return array<string,mixed> */
    private function createPayload(CreateDiscountData $input): array
    {
        return array_filter([
            'code' => $input->code,
            'type' => $input->type,
            'value' => $input->value,
            'min_subtotal' => $input->min_subtotal,
            'usage_limit' => $input->usage_limit,
            'once_per_buyer' => $input->once_per_buyer,
            'status' => $input->status,
            'starts_at' => $input->starts_at,
            'ends_at' => $input->ends_at,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
