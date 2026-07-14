<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Mail;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderNoteAdded;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Events\RefundCompleted;

/**
 * Transactional-email triggers (spec §6). One handler per mapped event; every handler
 * wraps the {@see CommerceMailer} call in try/catch so a rebound, throwing mailer can
 * never escape event dispatch and fail the persisted order/refund/note operation that
 * triggered it.
 *
 * `RefundFailed` is intentionally NOT mapped here: it is internal/operator-facing and
 * never emails the customer (spec §9) — a gateway failure does not prove a customer-visible
 * refund occurred.
 */
final class OrderMailListener
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceMailer $mailer,
    ) {
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->safeSend('order_placed', $event->order, []);
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->safeSend('order_paid', $event->order, []);
    }

    public function onOrderFulfilled(OrderFulfilled $event): void
    {
        $this->safeSend('order_fulfilled', $event->order, []);
    }

    public function onRefundCompleted(RefundCompleted $event): void
    {
        $this->safeSend('order_refunded', $event->order, $event->refund);
    }

    public function onOrderNoteAdded(OrderNoteAdded $event): void
    {
        if (($event->note['notify'] ?? false) !== true) {
            return;
        }

        $this->safeSend('order_note', $event->order, $event->note);
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    private function safeSend(string $template, array $order, array $payload): void
    {
        try {
            $this->mailer->send($this->context, $template, $order, $payload);
        } catch (\Throwable $e) {
            error_log("[commerce] order mail listener failed for template '{$template}': " . $e->getMessage());
        }
    }
}
