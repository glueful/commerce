<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Mail;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Transactional-email seam for Commerce (spec §6).
 *
 * Implementations must never throw out of a listener: send failures are logged and
 * surfaced via {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport} instead of
 * propagating. Apps may rebind this to bypass notifications entirely (e.g. a null mailer
 * in tests, or a fully custom transactional-email integration).
 */
interface CommerceMailer
{
    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload template-specific facts (e.g. the refund row for
     *        `order_refunded`, the note row for `order_note`); never the raw operator-facing
     *        `reason` text — that projection is the mailer/template's responsibility.
     */
    public function send(ApplicationContext $context, string $template, array $order, array $payload = []): void;
}
