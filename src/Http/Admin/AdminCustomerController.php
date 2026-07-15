<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Http\DTOs\CustomerListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\CustomerLookupQuery;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Customer admin surface (design spec §7) — pure order aggregation, NO dedicated
 * customer table. `index()` lists every distinct customer (grouped by user_uuid
 * when present, else normalized email) with totals; `show()` returns that same
 * aggregate for exactly one customer plus their recent orders. Identity kind is
 * never inferred from the `{key}` route value — `?by=user|email` is required
 * (design spec Resolved Decision 2, enforced by {@see CustomerLookupQuery}).
 *
 * Username enrichment via a SOFT-resolved `UserProviderInterface::findByUuid()`
 * (design spec §7): absent provider, or provider present but the uuid doesn't
 * resolve, both degrade to the raw aggregation with no `username` key added —
 * never an error.
 *
 * Address-book enrichment on `show()` (Task 9 seam, design spec §7): a
 * user-keyed detail (`?by=user`) carries an `addresses` key with every saved
 * address for that account; an email-keyed detail (`?by=email`) never gets
 * this key at all — a guest identity has no address book, since the book is
 * keyed by `user_uuid`, not email.
 */
final class AdminCustomerController
{
    private const RECENT_ORDERS_PER_PAGE = 25;

    public function __construct(
        private ApplicationContext $context,
        private ?CustomerAggregationRepository $customers = null,
        private ?OrderRepository $orders = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?UserProviderInterface $users = null,
        private ?AddressBookRepository $addresses = null,
    ) {
        $this->customers ??= app($context, CustomerAggregationRepository::class);
        $this->orders ??= app($context, OrderRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->addresses ??= app($context, AddressBookRepository::class);

        if ($this->users === null && container($context)->has(UserProviderInterface::class)) {
            $resolved = container($context)->get(UserProviderInterface::class);
            $this->users = $resolved instanceof UserProviderInterface ? $resolved : null;
        }
    }

    #[ApiOperation(summary: 'List customers aggregated from orders', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Customers retrieved')]
    public function index(CustomerListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $sort = $query->sort ?? 'last_order_at';
        $direction = $query->direction ?? 'desc';

        $result = $this->customers->paginate(
            $this->context,
            $this->tenants->tenantUuid($this->context),
            array_filter(
                ['email' => $query->email],
                static fn (mixed $value): bool => $value !== null && trim((string) $value) !== ''
            ),
            $sort,
            $direction,
            $page,
            $perPage
        );

        $items = array_map(fn (array $row): array => $this->enrich($row), $result['items']);

        return Response::paginated($items, $result['total'], $page, $perPage, null, 'Customers retrieved');
    }

    #[ApiOperation(summary: 'Get a customer aggregate and recent orders', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Customer retrieved')]
    #[ApiResponse(404, description: 'Customer not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function show(CustomerLookupQuery $query, Request $request, string $key): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $customer = $query->by === 'user'
            ? $this->customers->findByUser($this->context, $tenant, $key)
            : $this->customers->findByEmail($this->context, $tenant, $key);

        if ($customer === null) {
            throw new NotFoundException('Resource not found.');
        }

        $customer = $this->enrich($customer);

        $filters = $query->by === 'user'
            ? ['user_uuid' => $key]
            : ['email_normalized' => EmailNormalizer::normalize($key)];

        $recent = $this->orders->paginatedFor($this->context, $tenant, $filters, 1, self::RECENT_ORDERS_PER_PAGE);
        $customer['orders'] = $recent['items'];

        if ($query->by === 'user') {
            $customer['addresses'] = array_map(
                fn (array $row): array => $this->addressProjection($row),
                $this->addresses->forUser($this->context, $tenant, $key)
            );
        }

        return Response::success($customer, 'Customer retrieved');
    }

    /**
     * Internal-scoping columns (tenant_uuid, user_uuid) never leave this
     * whitelist — the address already belongs to the customer being viewed,
     * so those columns would be pure redundancy, not new information.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function addressProjection(array $row): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'label' => $row['label'],
            'address' => $row['address'],
            'is_default_shipping' => (bool) $row['is_default_shipping'],
            'is_default_billing' => (bool) $row['is_default_billing'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $customer
     * @return array<string,mixed>
     */
    private function enrich(array $customer): array
    {
        if ($customer['key_type'] !== 'user' || $this->users === null || $customer['user_uuid'] === null) {
            return $customer;
        }

        $identity = $this->users->findByUuid((string) $customer['user_uuid']);
        if ($identity === null) {
            return $customer;
        }

        $customer['username'] = $identity->username();

        return $customer;
    }
}
