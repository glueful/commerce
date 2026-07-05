<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Http\DTOs\CreateDiscountData;
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
    ) {
        $this->discounts ??= app($context, DiscountRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'List discounts', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Discounts retrieved')]
    public function index(Request $request): Response
    {
        return Response::success(
            $this->discounts->listAll($this->context, $this->tenants->tenantUuid($this->context)),
            'Discounts retrieved'
        );
    }

    #[ApiOperation(summary: 'Create a discount', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: CreateDiscountData::class)]
    #[ApiResponse(201, description: 'Discount created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(Request $request): Response
    {
        try {
            $row = $this->validated($this->input($request));
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
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $changes = $this->validated($this->input($request), partial: true);
            $this->discounts->update($this->context, $this->tenants->tenantUuid($this->context), $uuid, $changes);

            return Response::success(['uuid' => $uuid], 'Discount updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
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
}
