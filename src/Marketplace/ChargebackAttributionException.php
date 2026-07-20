<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see ChargebackService::attributeAndPost()} for an
 * operator-input rejection (design spec §2.5): the supplied
 * `(order_line_uuid, amount)` rows are empty, do not sum EXACTLY to the
 * chargeback's own amount, or reference an `order_line_uuid` that does not
 * belong to the chargeback's resolved order at all. A `\DomainException` --
 * a caller-input rejection Task 16's surface maps to a `422`, mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException}'s
 * identical convention -- never a runtime/integrity failure.
 *
 * This is distinct from the SEPARATE "over-attribution against the derived
 * remaining after earlier chargebacks/refunds" business condition (design
 * spec §2.5): that one is a DATA-STATE finding discovered only once every
 * line resolves, and is never thrown -- it transitions the chargeback to
 * `integrity_hold` instead, the same event-first "never guessed into a
 * posting" discipline {@see ChargebackService::ingest()} already applies to
 * T10's own unresolvable/incoherent classification.
 */
final class ChargebackAttributionException extends \DomainException
{
}
