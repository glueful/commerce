<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Extensions\Commerce\Cart\AddonSnapshot;

/**
 * Response-boundary whitelist for DRAFT `commerce_orders` rows (admin-order-creation
 * cycle 2, Task 9) -- the draft listing, the draft detail, and every draft
 * mutation response.
 *
 * It is deliberately {@see OrderProjection::FIELDS} PLUS exactly one column,
 * `draft_revision`, rather than an independently maintained list: the five
 * walk-in identity fields (`customer_name`, `phone_normalized`, `phone_display`,
 * `fulfillment_mode`, `origin`) are already on the admin wire from Task 6, and
 * deriving from that list means a column added to `commerce_orders` stays off
 * BOTH wires until someone names it. The one addition is the draft's optimistic
 * concurrency counter, which an editing client genuinely needs (it is what it
 * sends back as `expected_revision`) and which Task 6 deliberately kept OFF the
 * finalized-order wire.
 *
 * The PII ratchet is unchanged in the other direction: the storefront wire
 * ({@see \Glueful\Extensions\Commerce\Http\Storefront\StorefrontOrderProjection})
 * names none of the walk-in columns and none of them are added here, so nothing
 * in this task widens customer-facing exposure.
 */
final class DraftOrderProjection
{
    /** @var list<string> */
    public const FIELDS = [
        ...OrderProjection::FIELDS,
        'draft_revision',
    ];

    /**
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public static function forAdmin(array $order, array $lines = []): array
    {
        $projected = array_intersect_key($order, array_flip(self::FIELDS));
        $projected['draft_revision'] = (int) ($order['draft_revision'] ?? 0);
        $projected['lines'] = array_map([self::class, 'line'], $lines);

        return $projected;
    }

    /**
     * A draft line on the wire. Unlike the finalized-order line projection
     * ({@see AdminOrderController::linesProjection()}), this one KEEPS
     * `variant_uuid`: a draft editor's whole job is to add/replace lines, and
     * the variant is the identity it mutates against. `addons` still gets the
     * same SANITIZED echo every other surface uses -- never `addon_uuid`,
     * `choice_key`, or any other addon-definition internal.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private static function line(array $line): array
    {
        return [
            'uuid' => (string) ($line['uuid'] ?? ''),
            'variant_uuid' => (string) ($line['variant_uuid'] ?? ''),
            'product_name' => (string) ($line['product_name'] ?? ''),
            'sku' => (string) ($line['sku'] ?? ''),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'unit_price' => (int) ($line['unit_price'] ?? 0),
            'line_total' => (int) ($line['line_total'] ?? 0),
            'option_values' => is_array($line['option_values'] ?? null) ? $line['option_values'] : [],
            'addons' => AddonSnapshot::sanitize(is_array($line['addons'] ?? null) ? $line['addons'] : []),
        ];
    }
}
