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
use Glueful\Extensions\Commerce\Support\CommerceSettings;

/**
 * Transactional-email triggers (spec §6). One handler per mapped event; every handler
 * wraps the {@see CommerceMailer} call in try/catch so a rebound, throwing mailer can
 * never escape event dispatch and fail the persisted order/refund/note operation that
 * triggered it.
 *
 * **Nullable order email (admin-order-creation cycle 2, Task 10; design Ruling 4/7,
 * design spec §2.5.9).** A walk-in order legitimately has NO email address -- no
 * placeholder is ever invented -- so `commerce_orders.email` is nullable from
 * migration 022 onward. {@see self::safeSend()} therefore carries ONE shared
 * email-presence guard covering EVERY lifecycle template (placed, paid, fulfilled,
 * refunded, notifying note), placed there rather than in the five handlers precisely
 * so a future template cannot be added without it: "no email means no notification
 * attempt" is Ruling 7's wording, and one guard at the single send site is the only
 * arrangement that makes that true by construction. It is also why the guard lives
 * HERE and not in {@see CommerceMailer} implementations -- a host may rebind the
 * mailer, and the invariant must not be rebindable with it.
 *
 * The ADMIN-ORIGIN order-confirmation toggle (`commerce.order_confirmation`) is a
 * second, narrower gate that gets `OrderPlaced` alone: a counter sale is handed over
 * in person, so a merchant may reasonably want no "we received your order" mail for
 * admin-created orders while keeping every payment/fulfilment mail. It is checked
 * ONLY for `origin = 'admin'`, so storefront behaviour is byte-identical to
 * pre-Task-10 whatever the toggle says.
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

    /**
     * The ONE handler the admin-origin confirmation toggle governs (design spec
     * §2.5.9). The origin is read from the order's OWN `origin` column -- the
     * immutable fact written at creation time -- never from ambient request
     * state, so a replayed or requeued dispatch is judged identically to the
     * original. An order with no origin at all (a legacy row predating migration
     * 022) reads as `storefront`, which is exactly what the migration's own
     * backfill decided.
     */
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $origin = (string) ($event->order['origin'] ?? 'storefront');
        if ($origin === 'admin' && !CommerceSettings::orderConfirmation($this->context)) {
            return;
        }

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
     * The ONE send site, and therefore the ONE place the email-presence guard has
     * to live (see the class docblock). A null, non-string, or blank
     * `commerce_orders.email` short-circuits BEFORE the mailer is touched at all
     * -- not inside it, and not per template -- so an anonymous walk-in order
     * generates zero send attempts, zero log noise, and zero notification-store
     * rows across its entire lifecycle.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    private function safeSend(string $template, array $order, array $payload): void
    {
        $email = $order['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            return;
        }

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
     * (plain `$router->group(['prefix' => '/commerce'], ...)`, never `Router::apiVersion()`),
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
