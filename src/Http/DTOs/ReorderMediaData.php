<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Contracts\RequestData;

final class ReorderMediaData implements RequestData
{
    /**
     * Shape-checks each raw `positions` element in the controller (nested-DTO support
     * for arbitrary request arrays is pending — same temporary substitute documented
     * on {@see CreateRefundData}).
     *
     * @param list<array{uuid:string,position:int}>|null $positions
     */
    public function __construct(
        public readonly ?array $positions = null,
    ) {
    }
}
