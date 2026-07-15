<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Mail;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderNoteAdded;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Events\RefundCompleted;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;

/**
 * Transactional-email triggers (spec §6). One handler per mapped event; every handler
 * wraps the {@see CommerceMailer} call in try/catch so a rebound, throwing mailer can
 * never escape event dispatch and fail the persisted order/refund/note operation that
 * triggered it.
 *
 * `RefundFailed` is intentionally NOT mapped here: it is internal/operator-facing and
 * never emails the customer (spec §9) — a gateway failure does not prove a customer-visible
 * refund occurred.
 *
 * Digital-delivery wiring (spec §6, verbatim-binding): there is NO separate grant
 * listener. `onOrderPaid()` calls {@see DownloadGrantService::issueAndCollectForOrder()}
 * FIRST — grants commit (or already exist) before the mail is even attempted — then
 * passes ONLY that call's freshly-created raw tokens into the `order_paid` payload as
 * deep-link URLs. A physical order, or a second/idempotent re-fire for an
 * already-granted digital order, yields an empty payload byte-identical to the
 * pre-Layer-3 shape (no `downloads` key at all). If issuance throws, it is logged and
 * swallowed HERE — before {@see self::safeSend()} — so the ordinary paid email still
 * goes out with no links; §4.1 (lazy, order-authenticated) and the backfill CLI heal
 * the missing grants later. If sending later throws, {@see self::safeSend()}'s own
 * guard applies exactly as before; grants already committed are unaffected.
 */
final class OrderMailListener
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceMailer $mailer,
        // Nullable + lazily resolved (see downloadGrantService()) rather than
        // eagerly `??= app(...)`'d in the constructor: several existing tests
        // construct this listener directly with only the two args above, against a
        // lightweight container that has no DownloadGrantService binding at all —
        // an eager resolution there would throw at construction time. Mirrors
        // OrderController's identical lazy-accessor pattern for the same reason.
        private ?DownloadGrantService $downloadGrants = null,
    ) {
    }

    private function downloadGrantService(): DownloadGrantService
    {
        return $this->downloadGrants ??= app($this->context, DownloadGrantService::class);
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->safeSend('order_placed', $event->order, []);
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->safeSend('order_paid', $event->order, $this->digitalDownloadsPayload($event->order));
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

    /**
     * Issues (or reads back) this order's digital-download grants and turns ONLY the
     * raw tokens THIS call created into `{name, url}` deep links (spec §6). Never
     * blocks the paid email: any issuance failure is logged (never a raw token, hash,
     * or blob uuid — only the order uuid and the exception message) and swallowed,
     * returning an empty payload so `safeSend()` still fires with the plain template.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed> either `[]` or `['downloads' => list<array{name:string,url:string}>]`
     */
    private function digitalDownloadsPayload(array $order): array
    {
        try {
            $result = $this->downloadGrantService()->issueAndCollectForOrder($this->context, $order);
        } catch (\Throwable $e) {
            $orderUuid = is_string($order['uuid'] ?? null) ? $order['uuid'] : '?';
            error_log(
                "[commerce] order mail listener: digital-download grant issuance failed for order "
                . "{$orderUuid}: " . $e->getMessage()
            );

            return [];
        }

        $links = $this->deepLinksForNewGrants($result['grants'], $result['raw_tokens']);

        return $links === [] ? [] : ['downloads' => $links];
    }

    /**
     * @param list<array<string,mixed>> $grants every grant currently on the order
     * @param array<string,string> $rawTokens grant_uuid => raw token, ONLY for grants
     *     issued by THIS call (never re-derived — see {@see DownloadGrantService}).
     * @return list<array{name:string,url:string}>
     */
    private function deepLinksForNewGrants(array $grants, array $rawTokens): array
    {
        if ($rawTokens === []) {
            return [];
        }

        $links = [];
        foreach ($grants as $grant) {
            $uuid = (string) $grant['uuid'];
            if (!isset($rawTokens[$uuid])) {
                continue;
            }

            $links[] = [
                'name' => (string) $grant['name'],
                'url' => $this->deepLinkUrl($rawTokens[$uuid]),
            ];
        }

        return $links;
    }

    /**
     * Absolute deep-link URL (spec §6): the email is opened by an arbitrary mail
     * client, so a path-only value would be unusable — unlike the path-only
     * `cover_url`/`url` fields the storefront controllers return in JSON responses
     * to a caller that already knows its own API origin. `routes.php` registers this
     * exact `/commerce/downloads/{token}` path with no framework-injected API prefix
     * (plain `$router->group(['prefix' => '/commerce'], ...)`, never `versionGroup()`),
     * so prefixing with `app.urls.base` — the framework's own bare-origin config key
     * (see `api_url()`'s sibling doc comment in config/app.php) — reproduces the live
     * route exactly, without needing a live HTTP `Request` this listener never has.
     */
    private function deepLinkUrl(string $token): string
    {
        $base = rtrim((string) config($this->context, 'app.urls.base', 'http://localhost'), '/');

        return $base . '/commerce/downloads/' . $token;
    }
}
