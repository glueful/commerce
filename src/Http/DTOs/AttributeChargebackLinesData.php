<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/chargebacks/{uuid}/attribution` request body (design
 * spec §2.5, MV5a Task 16): operator-supplied `(order_line_uuid, amount)` attribution
 * rows for an `awaiting_attribution` PARTIAL chargeback. Per-element shape validation
 * happens in the controller (mirrors
 * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminRefundController::validateLines()}
 * -- nested-DTO support for arbitrary request arrays is pending); the sum-equals-amount
 * invariant is enforced by
 * {@see \Glueful\Extensions\Commerce\Marketplace\ChargebackService::attributeAndPost()}
 * itself (a `422` `ChargebackAttributionException`), not here.
 *
 * @see \Glueful\Extensions\Commerce\Http\Admin\AdminReserveController::attributeChargeback()
 */
final class AttributeChargebackLinesData implements RequestData
{
    /** @param list<array{order_line_uuid:string,amount:int}>|null $lines */
    public function __construct(
        public readonly ?array $lines = null,
    ) {
    }
}
