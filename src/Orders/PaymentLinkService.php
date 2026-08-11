<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Orders\Events\PaymentLinkEvents;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;

/**
 * THE PAYMENT-LINK AUTHORITY (payment-links Task 6, design spec §2.2): the one
 * place that decides who may have a payment link, what it may reveal, and for
 * how long. {@see PaymentLinkRepository} owns the row mechanics and contains no
 * policy; every policy statement lives here.
 *
 * ## Token custody, which is the whole point
 *
 * A payment link is a BEARER credential: whoever holds the token can open the
 * page and start a checkout. So the raw token has exactly TWO egress points in
 * the entire engine, and this class owns both:
 *
 *  1. {@see self::mint()}'s `rawToken` return value -- the one-time hand-off to
 *     a trusted caller that will deliver it (an operator copying a URL).
 *  2. {@see self::mintPublic()}'s composed URL, which embeds it EXACTLY ONCE.
 *
 * It appears nowhere else: not in the table (only `sha256` of it), not in an
 * audit payload ({@see PaymentLinkEvents}), not in an exception message
 * ({@see PaymentLinkException::publicUrlUnavailable()} takes no arguments for
 * precisely this reason), not in {@see LinkView} or {@see PaymentLinkAdminView},
 * and never in a parameter to the repository -- which by construction speaks
 * only in hashes, pinned by its own reflection test. `PaymentLinkServiceTest`'s
 * egress ratchet runs a full mint/resolve/match/revoke/mintPublic cycle and then
 * greps every touched table and every method's output for the token.
 *
 * The token is 32 bytes from `random_bytes()` rendered as 64 lowercase hex
 * ({@see self::TOKEN_BYTES}/{@see self::TOKEN_PATTERN}) -- 256 bits of CSPRNG
 * entropy, so guessing is not a threat model, and a fixed shape so the gate
 * below can be exact.
 *
 * ## STACK FRAMES ARE AN EGRESS POINT TOO (review round 1, Important 1)
 *
 * PHP records CALL ARGUMENTS in exception backtraces unless
 * `zend.exception_ignore_args` is On, and the framework's error handler writes
 * `getTraceAsString()` (arguments truncated to 15 characters) into the error log
 * while `ErrorResponseDTO::fromException()` can capture them untruncated. So any
 * unexpected throwable -- a deadlock, a driver error, a constraint violation --
 * raised while a raw token sits in a live frame's argument list puts at least a
 * 15-hex prefix of a live bearer credential into a log file. That is a real leak
 * with no application code involved at all.
 *
 * Two defenses, both applied here:
 *
 *  1. STRUCTURAL. {@see self::persistMint()} takes a `$tokenHash`, never a raw
 *     token, so the entire mint transaction -- every eligibility refusal, every
 *     repository call, every driver error inside it -- runs with no frame on the
 *     stack that holds the token at all. It is hashed in
 *     {@see self::mint()}/{@see self::mintPublic()}, where it is a LOCAL (locals
 *     never appear in backtrace arguments; only parameters do).
 *  2. SCOPE MINIMIZATION. Where the token must be a parameter --
 *     {@see self::resolveByToken()}, {@see self::matchCurrentToken()},
 *     {@see self::composePublicUrl()} -- it is hashed (or consumed) immediately
 *     and the parameter is then OVERWRITTEN with {@see self::REDACTED_TOKEN}
 *     before any I/O or any throw. Overwriting a parameter genuinely replaces
 *     what a later backtrace reports for that frame, which is why this is an
 *     assignment and not a comment saying "be careful".
 *
 * DEPLOYMENT REQUIREMENT: installs handling payment links MUST pin
 * `zend.exception_ignore_args=On`. The measures above make the engine safe
 * against its OWN frames; they cannot protect a caller that passes a token into
 * its own helper, and defense in depth is cheap here. Task 7's
 * `initiateByToken()` has exactly this shape and its implementer must apply the
 * same two rules.
 *
 * ## Why the shape gate comes before the database
 *
 * {@see self::resolveByToken()} is an UNAUTHENTICATED endpoint's entire
 * authority. Its refusals must be indistinguishable: an unknown token, a
 * malformed token, and another tenant's perfectly valid token all return ONE
 * generic null (controller 404). The shape gate runs FIRST so garbage never
 * reaches a query at all; after it passes, the path is straight-line --
 * hash, then a single tenant-scoped hashed lookup, with no early return that
 * would skip the hashing and give a prober a timing signal. Cross-tenant is
 * handled by the lookup's own tenant predicate, not by a comparison afterwards,
 * so there is no "found it, but not yours" branch to time.
 *
 * ## One transaction, one active link (Ruling 7)
 *
 * Mint is ONE transaction that locks the ORDER first, then revokes any prior
 * active link, then inserts the new one. The order lock -- not a partial unique
 * index, which the schema deliberately does not have -- is the authority:
 * concurrent mints serialize on it, so the loser observes the winner's committed
 * link, revokes it, and inserts its own. Exactly one active link survives, and
 * regenerate is just a mint that found a predecessor.
 * `PaymentLinkServicePgsqlTest` proves this with a genuinely separate OS
 * process, because SQLite's database-wide write lock would hide a missing order
 * lock entirely.
 *
 * The lock ORDER is order-then-link everywhere in this class (spec §2.2), which
 * is what keeps mint, revoke, and Task 7's two-phase initiation from
 * deadlocking against each other.
 *
 * ## Lazy transitions on read
 *
 * A link's terminal states are discovered, not scheduled: `expires_at` passing
 * makes it `expired`, and the order being paid makes it `consumed`. Both are
 * applied on read, by {@see self::applyLazyTransitions()}, so a link never
 * DISPLAYS a state the world has already moved past. Paid wins over expired: an
 * order that got paid was paid, whatever the link's TTL later did. A canceled or
 * refunded order transitions nothing -- the view reports the order's honest
 * state and lets the page say so.
 *
 * ## What this class deliberately does NOT do
 *
 * No `initiateByToken()` and no exposure guard -- those are Tasks 7 and 8. This
 * class stamps nothing on the provider-session columns; it only READS
 * `provider_session_issued_at` to publish the exposure flag both views carry.
 */
final class PaymentLinkService
{
    /**
     * The token shape, both generated and accepted: exactly 64 LOWERCASE hex
     * characters. `\A`/`\z` rather than `^`/`$` on purpose -- PHP's `$` also
     * matches before a trailing newline, so `^...$` would accept
     * `"<64 hex>\n"`, and a token that survives a stray newline is a token whose
     * shape gate is decorative. Same discipline as
     * {@see DraftFinalizationService::IDEMPOTENCY_KEY_PATTERN}.
     */
    public const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    /** 32 CSPRNG bytes -> 64 hex characters. */
    public const TOKEN_BYTES = 32;

    /**
     * What a raw-token PARAMETER is overwritten with once it has been hashed or
     * consumed -- see the class docblock's stack-frame section. A visible
     * sentinel rather than `''` or `unset()` so a backtrace that reaches a
     * reviewer reads as a deliberate redaction, not as a bug that dropped an
     * argument.
     */
    private const REDACTED_TOKEN = '[redacted]';

    /** The one order origin a payment link may be issued for (design spec §2.2). */
    public const ADMIN_ORIGIN = 'admin';

    /** The one order status a payment link may be issued for. */
    public const PAYABLE_STATUS = 'pending_payment';

    public function __construct(
        private OrderRepository $orders,
        private PaymentLinkRepository $links,
        private CurrentTenantResolver $tenants,
        /**
         * Soft dependency: a host with no public payment-link surface leaves
         * this null (or gets the engine's own
         * {@see UnavailablePaymentLinkPublicUrlProvider}). BOTH converge on the
         * same typed unavailable outcome, so there is one behaviour to reason
         * about rather than two.
         */
        private ?PaymentLinkPublicUrlProvider $publicUrls = null,
    ) {
    }

    /**
     * Issue a payment link for a tenant-owned, admin-origin, `pending_payment`
     * order and hand the RAW token back exactly once.
     *
     * This is the trusted form: the caller receives the bearer secret and is
     * responsible for it. Controllers and anything that sends should use
     * {@see self::mintPublic()} instead, which never exposes a bare token.
     *
     * `$ttlDays` is clamped into 1..30; null takes
     * `commerce.payment_links.ttl_days` (default 7), itself clamped -- see
     * {@see CommerceSettings::paymentLinkTtlDays()}. A caller can therefore not
     * mint an already-expired link, nor one that outlives the policy.
     *
     * `$now` is a parameter, never `time()`: the cron, the controller, and the
     * tests all supply their own clock, and TTL boundaries are asserted exactly.
     *
     * @return array{rawToken: string, link: PaymentLinkAdminView}
     * @throws PaymentLinkException `order_not_found` (404) for an unknown or
     *     cross-tenant order; `order_not_admin_origin` / `order_not_pending_payment`
     *     (409) for an ineligible one. A refusal writes NOTHING.
     */
    public function mint(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        ?int $ttlDays,
        string $actorUuid,
        ?\DateTimeImmutable $now = null
    ): array {
        // The token is a LOCAL here, never a parameter, so it cannot appear in
        // this frame's backtrace arguments; and `persistMint()` receives only
        // the hash, so nothing deeper can hold it either.
        $rawToken = self::generateToken();

        return [
            'rawToken' => $rawToken,
            'link' => $this->persistMint(
                $context,
                $tenant,
                $orderUuid,
                self::hashToken($rawToken),
                $ttlDays,
                $actorUuid,
                $now
            ),
        ];
    }

    /**
     * The controller/send-safe form: mint, and return the PUBLIC URL instead of
     * the bare token.
     *
     * Order of operations is the contract (design spec §2.2), not an
     * implementation detail: generate the token, ask the bound
     * {@see PaymentLinkPublicUrlProvider} to compose a URL, VALIDATE that URL --
     * all BEFORE the mint transaction opens. A missing binding, a null, a throw,
     * or a URL that fails validation therefore creates NO link row at all, so a
     * misconfigured host accumulates no orphan links and no operator is shown a
     * "link created" that nobody can open.
     *
     * The return carries no separate raw-token field. The token is present
     * exactly once, inside the URL, as its final path segment.
     *
     * @return array{url: string, link: PaymentLinkAdminView}
     * @throws PaymentLinkException `public_url_unavailable` before any write;
     *     plus every refusal {@see self::mint()} can raise.
     */
    public function mintPublic(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        ?int $ttlDays,
        string $actorUuid,
        ?\DateTimeImmutable $now = null
    ): array {
        $rawToken = self::generateToken();
        $url = $this->composePublicUrl($context, $rawToken);
        $tokenHash = self::hashToken($rawToken);
        // From here the token lives only inside `$url`, which is this frame's
        // return value anyway. Nothing below receives it.
        unset($rawToken);

        return [
            'url' => $url,
            'link' => $this->persistMint($context, $tenant, $orderUuid, $tokenHash, $ttlDays, $actorUuid, $now),
        ];
    }

    /**
     * Revoke the order's active payment link, by ORDER (design spec §2.2:
     * revocation is addressed by order, never by token -- an operator killing a
     * leaked link does not have, and must not need, the leaked value).
     *
     * Idempotent: an order with no active link is a clean no-op that audits
     * nothing. Unknown or cross-tenant orders still refuse, so revoke cannot be
     * used to probe which order uuids exist.
     *
     * @throws PaymentLinkException `order_not_found` (404)
     */
    public function revoke(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $actorUuid,
        ?\DateTimeImmutable $now = null
    ): void {
        $moment = self::moment($now);

        db($context)->transaction(function () use ($context, $tenant, $orderUuid, $actorUuid, $moment): void {
            // ORDER first, then link -- the lock order every payment-link path shares.
            $order = $this->orders->findByUuidForUpdate($context, $tenant, $orderUuid, includeDrafts: true);
            if ($order === null) {
                throw PaymentLinkException::orderNotFound();
            }

            $this->revokeActiveLink($context, $tenant, $orderUuid, $actorUuid, $moment);
        });
    }

    /**
     * The UNAUTHENTICATED resolve path: a raw token in, a closed public
     * {@see LinkView} or ONE generic null out.
     *
     * The tenant is HOST-RESOLVED ({@see CurrentTenantResolver}), never taken
     * from the request, so a token cannot be replayed against another store by
     * changing a parameter -- and since the lookup carries the tenant predicate
     * itself, a foreign token is simply not found rather than found-and-refused.
     *
     * Every refusal is the same null: malformed, unknown, cross-tenant, or an
     * order that has since vanished. The controller turns it into one 404.
     *
     * Applies the lazy terminal transitions on the way past
     * ({@see self::applyLazyTransitions()}), and REDACTS the commercial content
     * of a revoked link -- see the inline comment at that branch.
     *
     * HONEST CAVEAT about the lazy transition. This path is deliberately
     * UNLOCKED (it is an unauthenticated read; taking an order lock here would
     * hand every anonymous caller a contention lever). The transitions are
     * compare-and-set on `active`, so they are safe -- they cannot resurrect a
     * terminal link or overwrite another terminal state's stamp -- but they can
     * LOSE. A concurrent `revoke()` committing between this method's read and
     * its CAS means the returned view says `expired` or `consumed` while the row
     * now reads `revoked`. That divergence is bounded and harmless: all three
     * are non-payable terminal states, Task 7's initiation re-reads and re-checks
     * every predicate UNDER LOCK before any provider call, and the very next
     * resolve reports the committed `revoked`. What it does mean is that this
     * method's returned status is a READ-TIME OBSERVATION, not a guarantee about
     * the row -- so no caller may treat it as authority for a payment decision.
     */
    public function resolveByToken(
        ApplicationContext $context,
        string $rawToken,
        ?\DateTimeImmutable $now = null
    ): ?LinkView {
        // Shape gate BEFORE any database work: garbage never reaches a query.
        if (!self::isWellFormedToken($rawToken)) {
            return null;
        }

        $tokenHash = self::hashToken($rawToken);
        // The token has served its only purpose in this frame. Overwrite it
        // BEFORE any I/O so a driver error below cannot put it in a backtrace
        // (review round 1, Important 1 -- see the class docblock).
        $rawToken = self::REDACTED_TOKEN;

        $moment = self::moment($now);
        $tenant = $this->tenants->tenantUuid($context);

        $link = $this->links->findByTokenHash($context, $tenant, $tokenHash);
        if ($link === null) {
            return null;
        }

        $orderUuid = (string) $link['order_uuid'];
        $order = $this->orders->findByUuid($context, $tenant, $orderUuid, includeDrafts: true);
        if ($order === null) {
            return null;
        }

        $status = $this->applyLazyTransitions($context, $tenant, $link, $order, $moment);
        $expiresAt = self::normalizeStamp((string) $link['expires_at']);
        $exposed = $link['provider_session_issued_at'] !== null;

        // REVOKED is the one state whose holder is presumed hostile: the primary
        // reason an operator revokes is a leaked link, so the leaker must stop
        // reading the order's commercial content the moment they do. State only.
        // Note the deliberate asymmetry -- expired/consumed/canceled/refunded all
        // keep resolving in full, because those links were held legitimately and
        // their holder needs to understand what happened to the bill they were
        // sent. The line items are not fetched at all on this path.
        if ($status === PaymentLinkRepository::STATUS_REVOKED) {
            return LinkView::redacted(
                orderStatus: (string) $order['status'],
                linkStatus: $status,
                expiresAt: $expiresAt,
                providerSessionIssued: $exposed,
            );
        }

        return new LinkView(
            orderNumber: (string) ($order['order_number'] ?? ''),
            lines: $this->publicLines($context, $tenant, $orderUuid),
            currency: (string) $order['currency'],
            subtotal: (int) $order['subtotal'],
            discountTotal: (int) ($order['discount_total'] ?? 0),
            shippingTotal: (int) ($order['shipping_total'] ?? 0),
            taxTotal: (int) ($order['tax_total'] ?? 0),
            grandTotal: (int) $order['grand_total'],
            orderStatus: (string) $order['status'],
            linkStatus: $status,
            expiresAt: $expiresAt,
            providerSessionIssued: $exposed,
        );
    }

    /**
     * The AUTHENTICATED `mode=current` seam (design spec §2.2): "is the token I
     * am holding still this order's current link?" -- so an operator surface can
     * answer that question without ever reading the link table itself, and
     * without Commerce handing back a link identity for a token that is no
     * longer live.
     *
     * The 404/409 SPLIT the spec requires, expressed in the return type plus one
     * typed exception:
     *  - `null`  => the order is unknown or belongs to another tenant. The
     *    controller answers 404, identically to every other admin order surface,
     *    so this seam is not an order-existence oracle.
     *  - throws {@see PaymentLinkException::LINK_CHANGED} => the order IS yours,
     *    but the submitted token is not its current active link (stale,
     *    malformed, another tenant's, or there is no active link at all). The
     *    controller answers 409 `payment_link_changed`.
     *  - a {@see PaymentLinkAdminView} => it is the current link, with its state
     *    already lazily transitioned so the operator sees the same truth the
     *    payer's page would.
     *
     * ORDERING NOTE. The shape gate runs before any database work here too, but
     * a malformed token cannot be allowed to become a 404 -- that would let the
     * shape of the token decide the answer to an ownership question. So a
     * malformed token is refused as `payment_link_changed` outright, WITHOUT a
     * lookup: it is a constant answer that reveals nothing about the order,
     * while still being the honest one (a token that cannot be the current link
     * is not the current link).
     *
     * DOCUMENTED DIVERGENCE for Task 8's API docs: this means a malformed token
     * submitted against an UNKNOWN or CROSS-TENANT order answers 409, not the
     * 404 the spec's literal "missing/cross-tenant orders remain 404" wording
     * would suggest. The 404 guarantee holds for every WELL-FORMED token, which
     * is the case the wording is about; the malformed case is answered before
     * ownership is ever consulted, precisely so token shape cannot become an
     * order-existence oracle. T8's published error table should say so rather
     * than promise a 404 the engine deliberately does not give.
     */
    public function matchCurrentToken(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $rawToken,
        ?\DateTimeImmutable $now = null
    ): ?PaymentLinkAdminView {
        if (!self::isWellFormedToken($rawToken)) {
            throw PaymentLinkException::linkChanged();
        }

        $tokenHash = self::hashToken($rawToken);
        // Same reason as `resolveByToken()`: overwrite before any I/O or throw.
        $rawToken = self::REDACTED_TOKEN;

        $moment = self::moment($now);

        return db($context)->transaction(
            function () use ($context, $tenant, $orderUuid, $tokenHash, $moment): ?PaymentLinkAdminView {
                // ORDER first, then link.
                $order = $this->orders->findByUuidForUpdate($context, $tenant, $orderUuid, includeDrafts: true);
                if ($order === null) {
                    return null;
                }

                $link = $this->links->findActiveForOrderForUpdate($context, $tenant, $orderUuid);
                if ($link === null || !hash_equals((string) $link['token_hash'], $tokenHash)) {
                    throw PaymentLinkException::linkChanged();
                }

                $status = $this->applyLazyTransitions($context, $tenant, $link, $order, $moment);

                return new PaymentLinkAdminView(
                    linkUuid: (string) $link['uuid'],
                    status: $status,
                    expiresAt: self::normalizeStamp((string) $link['expires_at']),
                    providerSessionIssued: $link['provider_session_issued_at'] !== null,
                );
            }
        );
    }

    // =========================================================================
    // Mint internals
    // =========================================================================

    /**
     * The SHARED mint transaction, reached by both {@see self::mint()} and
     * {@see self::mintPublic()} -- one implementation of Ruling 7, so the
     * public and trusted forms cannot drift in eligibility, TTL, locking, or
     * audit.
     *
     * It receives an already-hashed token rather than generating one, which is
     * exactly what lets `mintPublic()` compose and validate the URL before this
     * transaction ever opens -- and, since the parameter is the HASH, what keeps
     * the raw token out of every stack frame inside the transaction. Review
     * round 1, Important 1: a deadlock or driver error in here must not be able
     * to write a bearer credential into an error log through
     * `getTraceAsString()`.
     */
    private function persistMint(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $tokenHash,
        ?int $ttlDays,
        string $actorUuid,
        ?\DateTimeImmutable $now
    ): PaymentLinkAdminView {
        $moment = self::moment($now);
        $ttl = CommerceSettings::paymentLinkTtlDays($context, $ttlDays);

        return db($context)->transaction(
            function () use (
                $context,
                $tenant,
                $orderUuid,
                $tokenHash,
                $ttl,
                $actorUuid,
                $moment
            ): PaymentLinkAdminView {
                // 1. ORDER lock. Everything after this is serialized per order,
                //    which is what makes "revoke prior, insert new" atomic.
                //    Drafts are included so an ineligible draft gets an honest
                //    `order_not_pending_payment` rather than a misleading 404.
                $order = $this->orders->findByUuidForUpdate($context, $tenant, $orderUuid, includeDrafts: true);
                if ($order === null) {
                    throw PaymentLinkException::orderNotFound();
                }

                $origin = (string) ($order['origin'] ?? '');
                if ($origin !== self::ADMIN_ORIGIN) {
                    throw PaymentLinkException::orderNotAdminOrigin($origin);
                }

                $status = (string) $order['status'];
                if ($status !== self::PAYABLE_STATUS) {
                    throw PaymentLinkException::orderNotPendingPayment($status);
                }

                // 2. Revoke whatever was active. A regenerate is nothing more
                //    than a mint that found a predecessor.
                $this->revokeActiveLink($context, $tenant, $orderUuid, $actorUuid, $moment);

                // 3. Insert the replacement.
                $linkUuid = Utils::generateNanoID();
                $expiresAt = $moment->setTimezone(new \DateTimeZone('UTC'))->modify("+{$ttl} days");
                $this->links->insert(
                    $context,
                    $tenant,
                    $linkUuid,
                    $orderUuid,
                    $tokenHash,
                    $expiresAt,
                    $actorUuid,
                    $moment
                );
                $this->orders->recordEvent(
                    $context,
                    $orderUuid,
                    PaymentLinkEvents::MINTED,
                    ['link_uuid' => $linkUuid],
                    $actorUuid
                );

                return new PaymentLinkAdminView(
                    linkUuid: $linkUuid,
                    status: PaymentLinkRepository::STATUS_ACTIVE,
                    expiresAt: self::stamp($expiresAt),
                    providerSessionIssued: false,
                );
            }
        );
    }

    /**
     * The SHARED revocation mechanic, used by the explicit {@see self::revoke()}
     * and by a regenerate's supersede step. MUST be called with the order
     * already locked.
     *
     * Reads the current link under its own row lock purely so the audit row can
     * NAME it; the transition itself is the repository's order-wide
     * compare-and-set, which also mops up the transient multi-active state the
     * schema permits by design. No active link means no write and no audit row,
     * which is what makes revoke idempotent in the trail as well as the table.
     */
    private function revokeActiveLink(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $actorUuid,
        \DateTimeImmutable $now
    ): void {
        $current = $this->links->findActiveForOrderForUpdate($context, $tenant, $orderUuid);
        $revoked = $this->links->revokeActiveForOrder($context, $tenant, $orderUuid, $now);

        if ($revoked < 1 || $current === null) {
            return;
        }

        $this->orders->recordEvent(
            $context,
            $orderUuid,
            PaymentLinkEvents::REVOKED,
            ['link_uuid' => (string) $current['uuid']],
            $actorUuid
        );
    }

    /**
     * Compose and validate the public URL, in memory, BEFORE anything is
     * persisted.
     *
     * Every failure -- no binding, a null, a throw, or a URL that fails any
     * check -- collapses to the SAME typed unavailable outcome. The provider is
     * wrapped in a catch because a host implementation is third-party code whose
     * exception message might quote the URL (and therefore the token); swallowing
     * it and raising our own argument-less refusal is what keeps the token out of
     * logs.
     *
     * @throws PaymentLinkException `public_url_unavailable`
     */
    private function composePublicUrl(ApplicationContext $context, string $rawToken): string
    {
        if ($this->publicUrls === null) {
            $rawToken = self::REDACTED_TOKEN;

            throw PaymentLinkException::publicUrlUnavailable();
        }

        try {
            $url = $this->publicUrls->urlFor($context, $rawToken);
        } catch (\Throwable) {
            // The provider's own exception is swallowed rather than chained: a
            // third-party message (or ITS backtrace, which holds the token as an
            // argument to `urlFor()`) must not ride out of here into a log.
            $rawToken = self::REDACTED_TOKEN;

            throw PaymentLinkException::publicUrlUnavailable();
        }

        // Validate while the token is still available, then redact the parameter
        // before the throw -- so the refusal's own backtrace is token-free.
        $valid = $url !== null && self::isValidPublicUrl($url, $rawToken);
        $rawToken = self::REDACTED_TOKEN;

        if (!$valid) {
            throw PaymentLinkException::publicUrlUnavailable();
        }

        return (string) $url;
    }

    /**
     * The URL contract from design spec §2.2, checked exactly.
     *
     * Absolute HTTPS with a host, and NO userinfo, port, query, or fragment: a
     * token in a query string is copied into access logs, proxy logs, and
     * `Referer` headers by machines nobody controls, and userinfo/port are
     * phishing and mis-routing surface with no legitimate use here.
     *
     * The token must appear in the WHOLE URL exactly once (`substr_count` over
     * the raw string, not just the path -- a host or path prefix that repeated
     * it would be a second copy in a second place), and be the FINAL path
     * segment. "Final" is strict: a trailing slash makes the last segment empty,
     * which is a different URL that most routers treat differently, so it is
     * refused rather than silently normalized.
     */
    private static function isValidPublicUrl(string $url, string $rawToken): bool
    {
        if ($url === '' || substr_count($url, $rawToken) !== 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return false;
        }

        foreach (['user', 'pass', 'port', 'query', 'fragment'] as $forbidden) {
            if (isset($parts[$forbidden])) {
                return false;
            }
        }

        $path = (string) ($parts['path'] ?? '');
        $segments = explode('/', $path);

        return end($segments) === $rawToken;
    }

    // =========================================================================
    // Shared read-side policy
    // =========================================================================

    /**
     * The lazy terminal transitions (design spec §2.2), applied on every read
     * that publishes a link's state so the state shown is never one the world
     * has already left behind.
     *
     * Only an `active` link can transition, and PAID WINS OVER EXPIRED: an order
     * that was paid was paid, whatever the TTL did afterwards, and `consumed` is
     * the honest record of why the link is finished. A canceled or refunded order
     * transitions NOTHING -- the link stays as it is and the view reports the
     * order's real status, which is what lets the page say "this order was
     * canceled" instead of pretending the link merely lapsed.
     *
     * The repository transitions are compare-and-set on `active`, so a
     * concurrent revoke or a second reader racing here simply loses and the
     * returned status still reflects what THIS READER OBSERVED -- which is not
     * necessarily what the row now holds. On the unlocked
     * {@see self::resolveByToken()} path a concurrent revoke can commit between
     * the read and the CAS, leaving the caller with `expired`/`consumed` while
     * the row reads `revoked`. All three are non-payable, and Task 7 re-checks
     * every predicate under lock, so the divergence is cosmetic -- but it is
     * real, and this return value is an observation, not an authority.
     *
     * @param array<string,mixed> $link
     * @param array<string,mixed> $order
     * @return string the EFFECTIVE status to publish
     */
    private function applyLazyTransitions(
        ApplicationContext $context,
        string $tenant,
        array $link,
        array $order,
        \DateTimeImmutable $now
    ): string {
        $status = (string) $link['status'];
        if ($status !== PaymentLinkRepository::STATUS_ACTIVE) {
            return $status;
        }

        $linkUuid = (string) $link['uuid'];

        if ((string) $order['status'] === 'paid') {
            $this->links->consume($context, $tenant, $linkUuid, $now);

            return PaymentLinkRepository::STATUS_CONSUMED;
        }

        // The expiry boundary is EXCLUSIVE, matching
        // PaymentLinkRepository::guardRelevantLinks()'s `expires_at > now`: at
        // the stamp itself the link has lapsed.
        if (self::normalizeStamp((string) $link['expires_at']) <= self::stamp($now)) {
            $this->links->expire($context, $tenant, $linkUuid, $now);

            return PaymentLinkRepository::STATUS_EXPIRED;
        }

        return $status;
    }

    /**
     * The public line projection: NAME and QUANTITY only.
     *
     * Not sku, not variant uuid, not unit price, not addons, not option values --
     * see {@see LinkView}'s allowlist rationale. A payer needs to recognise the
     * bill; the totals carry the money.
     *
     * @return list<array{name: string, quantity: int}>
     */
    private function publicLines(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        return array_map(
            static fn (array $line): array => [
                'name' => (string) $line['product_name'],
                'quantity' => (int) $line['quantity'],
            ],
            $this->orders->linesForOrder($context, $tenant, $orderUuid)
        );
    }

    // =========================================================================
    // Token primitives
    // =========================================================================

    /** 32 CSPRNG bytes rendered as 64 lowercase hex characters. */
    private static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * PRIVATE on purpose. Callers must never pre-gate a token themselves and
     * then branch: this class is the single authority on what a token is worth,
     * and a second, caller-side gate is exactly how one surface ends up 404ing
     * where another 409s. Task 8's controller calls {@see self::resolveByToken()}
     * (or {@see self::matchCurrentToken()}) and maps the ONE answer it gets.
     */
    private static function isWellFormedToken(string $rawToken): bool
    {
        return preg_match(self::TOKEN_PATTERN, $rawToken) === 1;
    }

    /**
     * The ONE hashing definition. SHA-256 without a salt or a work factor is
     * correct here and a password hash would be wrong: the input is 256 bits of
     * CSPRNG entropy, so there is no dictionary to attack and no need to slow a
     * lookup that must be a single indexed equality on
     * `(tenant_uuid, token_hash)`.
     */
    private static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    // =========================================================================
    // Clock
    // =========================================================================

    /** The injected clock, or the real one — normalized to UTC either way. */
    private static function moment(?\DateTimeImmutable $now): \DateTimeImmutable
    {
        return ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The canonical UTC `Y-m-d H:i:s` form every payment-link timestamp uses. */
    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * A STORED timestamp reduced to that same canonical form. PostgreSQL may
     * return a fractional-second suffix the driver never wrote, so a stored
     * value is re-parsed rather than compared or published byte-for-byte --
     * identical reasoning to {@see PaymentLinkRepository}'s own normalization.
     */
    private static function normalizeStamp(string $stored): string
    {
        try {
            return self::stamp(new \DateTimeImmutable($stored, new \DateTimeZone('UTC')));
        } catch (\Exception) {
            return $stored;
        }
    }
}
