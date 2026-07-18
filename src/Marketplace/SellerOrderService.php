<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Seller-scoped order READ (design spec §6.1/§2.12, MV2 Task 8): list +
 * detail, scoped to exactly one seller, over the payment-confirmed
 * partitions {@see SellerOrderRepository::confirmedForSeller()}/
 * {@see SellerOrderRepository::confirmedForSellerByUuid()} already enforce
 * (`confirmed_at IS NOT NULL`). `orderUuidForFulfill()` is the same
 * confirmed-scoped read, reused by {@see \Glueful\Extensions\Commerce\Http\Seller\SellerOrderController::fulfill()}
 * to resolve the parent order uuid the route itself doesn't carry, BEFORE
 * delegating the actual mutation to {@see SellerOrderFulfillmentService} --
 * this class never writes.
 *
 * Every read is additionally predicated by `commerce_orders.marketplace_partitioned`
 * (re-checked here, not merely trusted from the child row) -- a seller order
 * whose parent is somehow non-partitioned is the SAME non-revealing 404 as
 * an unknown one (design spec §6.4), mirroring
 * {@see SellerOrderFulfillmentService::fulfill()}'s own defense-in-depth
 * check.
 *
 * The seller detail response's `shipping_address` is a STRICT,
 * field-by-field allowlist -- never a spread of the raw `commerce_orders
 * .addresses['shipping']` JSON -- exposing only
 * `{name, company, line1, line2, city, region, postcode, country, phone}`
 * (design spec §2.12). Billing address, email, user uuid, guest/download/
 * payment tokens, and order metadata are never read by this class at all.
 * `name` is assembled from a direct name-shaped field or composed from
 * first/last-name-shaped fields when absent; `region`/`postcode` accept the
 * common key aliases a caller's inline/address-book address may have used
 * (`state`/`province`, `postal_code`/`zip`/`zip_code`).
 */
final class SellerOrderService
{
    public function __construct(
        private SellerOrderRepository $sellerOrders,
        private OrderRepository $orders,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @param array{fulfillment_status?: ?string} $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(
        ApplicationContext $context,
        string $sellerUuid,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $tenant = $this->tenants->tenantUuid($context);
        $rows = $this->sellerOrders->confirmedForSeller($context, $tenant, $sellerUuid);

        $status = $filters['fulfillment_status'] ?? null;
        if ($status !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['fulfillment_status'] === $status
            ));
        }

        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'items' => array_map(fn (array $row): array => $this->listProjection($row), $items),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed> */
    public function detail(ApplicationContext $context, string $sellerUuid, string $sellerOrderUuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        [$child, $order] = $this->requireConfirmedWithOrder($context, $tenant, $sellerUuid, $sellerOrderUuid);

        $lines = array_values(array_filter(
            $this->orders->linesForOrder($context, $tenant, (string) $child['order_uuid']),
            static fn (array $line): bool => (string) ($line['seller_uuid'] ?? '') === $sellerUuid
        ));

        $addresses = is_array($order['addresses'] ?? null) ? $order['addresses'] : [];
        $shipping = is_array($addresses['shipping'] ?? null) ? $addresses['shipping'] : [];

        return array_merge($this->listProjection($child), [
            'lines' => array_map(fn (array $line): array => $this->lineProjection($line), $lines),
            'shipping_address' => $this->shippingAddressProjection($shipping),
        ]);
    }

    /**
     * The confirmed-scoped resolve `SellerOrderController::fulfill()` needs
     * BEFORE calling {@see SellerOrderFulfillmentService::fulfill()}, which
     * itself requires the parent order uuid the fulfill route never carries.
     * Same non-revealing 404 as {@see self::detail()} for an unconfirmed,
     * cross-seller, unknown, or non-partitioned seller order -- the mutation
     * is never even attempted once this rejects.
     */
    public function orderUuidForFulfill(
        ApplicationContext $context,
        string $sellerUuid,
        string $sellerOrderUuid
    ): string {
        $tenant = $this->tenants->tenantUuid($context);
        [$child] = $this->requireConfirmedWithOrder($context, $tenant, $sellerUuid, $sellerOrderUuid);

        return (string) $child['order_uuid'];
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [child, parent order]
     */
    private function requireConfirmedWithOrder(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $sellerOrderUuid
    ): array {
        $child = $this->sellerOrders->confirmedForSellerByUuid($context, $tenant, $sellerUuid, $sellerOrderUuid);
        if ($child === null) {
            throw new NotFoundException('Resource not found.');
        }

        $order = $this->orders->findByUuid($context, $tenant, (string) $child['order_uuid']);
        if ($order === null || !(bool) ($order['marketplace_partitioned'] ?? false)) {
            throw new NotFoundException('Resource not found.');
        }

        return [$child, $order];
    }

    /**
     * Field-by-field allowlist shared by the list and detail shapes --
     * never a raw `commerce_seller_orders` row spread. Excludes the
     * internal `id`, `tenant_uuid`, and `revision` columns.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function listProjection(array $row): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'order_uuid' => (string) $row['order_uuid'],
            'seller_reference' => (string) $row['seller_reference'],
            'partition_number' => (int) $row['partition_number'],
            'currency' => (string) $row['currency'],
            'subtotal' => (int) $row['subtotal'],
            'allocated_discount' => (int) $row['allocated_discount'],
            'allocated_shipping_discount' => (int) $row['allocated_shipping_discount'],
            'allocated_shipping' => (int) $row['allocated_shipping'],
            'allocated_tax' => (int) $row['allocated_tax'],
            'attributed_total' => (int) $row['attributed_total'],
            'confirmed_at' => $row['confirmed_at'],
            'fulfillment_status' => (string) $row['fulfillment_status'],
            'fulfilled_at' => $row['fulfilled_at'],
            'carrier' => $row['carrier'],
            'tracking_number' => $row['tracking_number'],
            'tracking_url' => $row['tracking_url'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Allowlisted to exactly {product_name, sku, quantity, unit_price,
     * line_total, option_values, addons} -- the SAME line whitelist
     * {@see \Glueful\Extensions\Commerce\Http\Storefront\OrderController::linesProjection()}
     * uses, never the internal `uuid`/`variant_uuid`/`order_uuid`/
     * `seller_uuid`/`discount_amount`/`tax_amount` columns
     * {@see OrderRepository::linesForOrder()} also returns.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function lineProjection(array $line): array
    {
        return [
            'product_name' => (string) ($line['product_name'] ?? ''),
            'sku' => (string) ($line['sku'] ?? ''),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'unit_price' => (int) ($line['unit_price'] ?? 0),
            'line_total' => (int) ($line['line_total'] ?? 0),
            'option_values' => is_array($line['option_values'] ?? null) ? $line['option_values'] : [],
            'addons' => AddonSnapshot::sanitize(is_array($line['addons'] ?? null) ? $line['addons'] : []),
        ];
    }

    /**
     * The §2.12 shipping-only allowlist, built field by field -- NEVER a
     * spread of `$address`. Exactly `{name, company, line1, line2, city,
     * region, postcode, country, phone}`; every other key present on the
     * raw shipping address (and the entire billing address, email, user
     * uuid, tokens, and order metadata this method is never even handed)
     * is excluded by construction.
     *
     * @param array<string,mixed> $address
     * @return array{
     *     name: ?string, company: ?string, line1: ?string, line2: ?string,
     *     city: ?string, region: ?string, postcode: ?string, country: ?string, phone: ?string
     * }
     */
    private function shippingAddressProjection(array $address): array
    {
        return [
            'name' => $this->assembleName($address),
            'company' => $this->addressField($address, ['company']),
            'line1' => $this->addressField($address, ['line1', 'address1', 'street1', 'line_1']),
            'line2' => $this->addressField($address, ['line2', 'address2', 'street2', 'line_2']),
            'city' => $this->addressField($address, ['city']),
            'region' => $this->addressField($address, ['region', 'state', 'province']),
            'postcode' => $this->addressField($address, ['postcode', 'postal_code', 'zip', 'zip_code']),
            'country' => $this->addressField($address, ['country']),
            'phone' => $this->addressField($address, ['phone', 'telephone']),
        ];
    }

    /**
     * A direct name-shaped field wins; otherwise assembled from stored
     * first/last-name-shaped recipient fields (design spec §2.12 -- "name
     * may be assembled from the stored recipient fields").
     *
     * @param array<string,mixed> $address
     */
    private function assembleName(array $address): ?string
    {
        $direct = $this->addressField($address, ['name', 'full_name', 'recipient_name', 'recipient']);
        if ($direct !== null) {
            return $direct;
        }

        $first = $this->addressField($address, ['first_name', 'given_name']);
        $last = $this->addressField($address, ['last_name', 'family_name', 'surname']);
        $combined = trim(($first ?? '') . ' ' . ($last ?? ''));

        return $combined === '' ? null : $combined;
    }

    /**
     * @param array<string,mixed> $address
     * @param list<string> $keys tried in order; the first non-empty scalar wins
     */
    private function addressField(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            if (is_scalar($value)) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }
}
