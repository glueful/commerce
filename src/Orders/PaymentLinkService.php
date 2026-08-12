<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\Events\PaymentLinkEvents;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\HttpsUrl;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
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
 * its own helper, and defense in depth is cheap here.
 * {@see self::initiateByToken()} has exactly this shape and applies both rules:
 * the parameter is overwritten before any I/O, and everything below it speaks
 * in hashes and uuids only.
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
 * ## Initiation is TWO PHASES, and that is a correctness requirement
 *
 * {@see self::initiateByToken()} (Task 7, design spec §2.2) turns a click into
 * a provider checkout session, and the ONE rule that shapes it is: NO PROVIDER
 * CALL RUNS INSIDE A TRANSACTION OR WHILE ROW LOCKS ARE HELD. A payment gateway
 * is a network round trip with a multi-second tail; holding this order's row
 * lock across it would block every operator edit, every sweep, and every other
 * click on the same order for that whole time, and a provider timeout would
 * hold locks until the driver gave up.
 *
 * So:
 *  - PHASE A (transaction): lock order, lock link, revalidate every predicate,
 *    ATOMICALLY CLAIM the fixed-hour rate window, capture the link/order
 *    identity, COMMIT. The claim happens here -- before the provider call, not
 *    after -- because a budget that is only consumed by SUCCESSFUL initiations
 *    protects nothing against a link being hammered.
 *  - PROVIDER LEG (no transaction, no locks): resolve and validate the host's
 *    return/cancel URLs, build the ordinary `commerce_order` PayableReference
 *    from the live order, call the collector.
 *  - PHASE B (transaction): relock order then link BY UUID, RECHECK EVERY
 *    PREDICATE, validate the collector's answer, stamp
 *    `provider_session_issued_at`, audit, COMMIT, and only then return the URL.
 *
 * Phase B is not paranoia: the whole point of releasing the locks is that the
 * world may move while the provider is thinking. A revoke, an admin cancel, an
 * OrderPaid, or even {@see self::resolveByToken()}'s own UNLOCKED lazy expiry
 * can all commit in that gap. When they do, Phase B refuses -- the provider
 * attempt stays server-side and NO URL is exposed.
 *
 * Every failure mode of the provider leg is a TYPED refusal
 * ({@see PaymentLinkException}'s initiation codes): `manual`, no URL, an
 * untrusted URL, a thrown provider exception (payvia 2.6's ensure-live raises
 * typed renewal-unavailable errors on repeat initiate, and Commerce programs
 * against the CONTRACT, so it catches `\Throwable` and maps it), and a missing
 * or insecure return route. Never an empty redirect, never an open one, never a
 * provider exception escaping to an anonymous browser.
 *
 * HONEST LIMIT on that claim (review round 1, minor 4): it covers every
 * PAYER-FACING outcome, not every throwable. A database driver failure -- a
 * lost connection, a deadlock, a constraint violation -- still escapes
 * initiation UNTYPED, as it does from every other method here. Those frames are
 * proven token-free, but they are not states: Task 8's controller must map any
 * unknown throwable from `initiateByToken()` to a BODILESS 500 rather than
 * assuming the typed set is exhaustive.
 *
 * INITIATION ALSO REFUSES TO RUN INSIDE A CALLER'S TRANSACTION -- see
 * {@see NestedInitiationTransactionException}, which explains why a nested
 * Phase A would silently hold locks across the provider call.
 *
 * ## What this class deliberately does NOT do
 *
 * No exposure guard -- that is Task 8. This class STAMPS
 * `provider_session_issued_at` (Phase B) and READS it to publish the exposure
 * flag both views carry, but it does not decide what a sweep or a cancel may do
 * about it.
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

    /** The collector statuses the payments contract defines. */
    private const STATUS_OK = 'ok';
    private const STATUS_MANUAL = 'manual';

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
        /**
         * The payment provider seam, programmed against the CONTRACT
         * ({@see PaymentCollector}) and never against a concrete gateway --
         * payvia's ensure-live implementation sits behind this interface and
         * its version is not this engine's business. Null degrades to the same
         * {@see ManualPaymentCollector} default
         * `CommerceServiceProvider::makePaymentCollector()` uses, so an
         * install with no gateway answers a typed `checkout_manual` rather
         * than a container error or a null dereference.
         */
        private ?PaymentCollector $collector = null,
        /**
         * The host's payment-link return/cancel routes. Null and a bound
         * {@see UnavailablePaymentLinkReturnUrlProvider} converge on the same
         * typed refusal; §2.2 forbids any fallback here.
         */
        private ?PaymentLinkReturnUrlProvider $returnUrls = null,
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

    /**
     * The order's CURRENT link, as a closed {@see PaymentLinkAdminView} -- the
     * TOKEN-FREE status read behind the `orders.payment_link.show` catalog entry
     * (payment-links Task 8, design spec §2.2: "`show` returns state/expiry/
     * exposure only -- never token or hash").
     *
     * The sibling of {@see self::matchCurrentToken()} for a caller that holds NO
     * token and must not need one: an operator surface asking "does this order
     * have a live link, and is a payer already inside a checkout?" is asking
     * about the ORDER, and answering it by making the client present a bearer
     * credential would mean that credential has to be STORED somewhere to be
     * presented. Thallo never reads the link table directly, and after Task 12's
     * one-time URL hand-off it holds no token at all -- this is how its admin
     * card stays truthful anyway.
     *
     * Same transaction, same lock order (ORDER, then link) and the same lazy
     * terminal transitions as every other read that publishes a link's state, so
     * an operator is never shown a status the payer's own page would contradict.
     *
     * `null` means "no active link" (never minted, or the current one was
     * revoked) -- never "no such order": an unknown or cross-tenant order is the
     * same typed `order_not_found` every other method here raises.
     *
     * @throws PaymentLinkException `order_not_found` (404)
     */
    public function currentLink(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        ?\DateTimeImmutable $now = null
    ): ?PaymentLinkAdminView {
        $moment = self::moment($now);

        return db($context)->transaction(
            function () use ($context, $tenant, $orderUuid, $moment): ?PaymentLinkAdminView {
                // ORDER first, then link. Drafts are included so an ineligible
                // draft answers "no link" rather than a misleading 404.
                $order = $this->orders->findByUuidForUpdate($context, $tenant, $orderUuid, includeDrafts: true);
                if ($order === null) {
                    throw PaymentLinkException::orderNotFound();
                }

                $link = $this->links->findActiveForOrderForUpdate($context, $tenant, $orderUuid);
                if ($link === null) {
                    return null;
                }

                return new PaymentLinkAdminView(
                    linkUuid: (string) $link['uuid'],
                    status: $this->applyLazyTransitions($context, $tenant, $link, $order, $moment),
                    expiresAt: self::normalizeStamp((string) $link['expires_at']),
                    providerSessionIssued: $link['provider_session_issued_at'] !== null,
                );
            }
        );
    }

    // =========================================================================
    // Initiation: two phases, with the provider call strictly between them
    // =========================================================================

    /**
     * Turn a payment-link click into a provider checkout session, and return
     * the ONE thing the caller may redirect to (design spec §2.2).
     *
     * UNAUTHENTICATED, like {@see self::resolveByToken()}: the tenant is
     * host-resolved and the token is the only credential. So the shape gate
     * runs first, the parameter is redacted the instant it has been hashed, and
     * every "you cannot pay with this" refusal collapses to ONE generic
     * {@see PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE} -- malformed,
     * unknown, another tenant's, revoked, superseded, expired, consumed, and
     * "that order is no longer awaiting payment" are indistinguishable from
     * outside, exactly as `resolveByToken()`'s single null is.
     *
     * ## The shape of the thing
     *
     * See the class docblock for WHY. What it does, in order:
     *
     *  1. PHASE A ({@see self::claimInitiation()}), one transaction: lock the
     *     order, lock the link, revalidate active + unexpired + order
     *     `pending_payment`, claim one unit of the fixed UTC hour window, and
     *     capture the link/order identity. Commit.
     *  2. PROVIDER LEG ({@see self::openProviderSession()}), NO transaction and
     *     NO locks: resolve and validate the host return/cancel URLs, build the
     *     ordinary `commerce_order` payable from the LIVE order, call the
     *     collector.
     *  3. PHASE B ({@see self::confirmInitiation()}), one transaction: relock
     *     order then link BY UUID, recheck EVERY predicate, validate
     *     `status='ok'` plus the checkout URL, stamp
     *     `provider_session_issued_at`, audit. Commit.
     *
     * Only after step 3 commits does the URL leave this method. If the world
     * moved during step 2 -- a revoke, an admin cancel, an OrderPaid, or an
     * unlocked `resolveByToken()` lazily expiring the link -- step 3 refuses and
     * the caller gets a typed non-payable, not a redirect.
     *
     * IDEMPOTENCY IS THE PROVIDER'S. There is no caller-supplied key: the
     * {@see PaymentCollector} contract requires implementations to be
     * idempotent per `(type, id)`, so repeated clicks converge on the one live
     * session rather than creating a second charge. The engine's job is only to
     * bound how often it may ask (the hour window) and to make sure it never
     * asks on behalf of a link that has stopped being payable.
     *
     * BUDGET IS CONSUMED BY ATTEMPTS, NOT BY SUCCESSES. A claim that is followed
     * by a provider failure is NOT refunded. That is deliberate: the window
     * exists to protect the gateway and the payer from a shared URL being
     * hammered, and a failing gateway is precisely when hammering is most
     * likely and most useless.
     *
     * MUST NOT BE CALLED INSIDE A TRANSACTION (review round 1, Important 1).
     * `Connection::transaction()` delegates to an already-active
     * TransactionManager, so a nested Phase A would "commit" a mere savepoint
     * and the caller's transaction would hold this order's and link's row locks
     * across the provider network call -- silently reintroducing the exact
     * failure the two phases exist to prevent. There is no safe automatic
     * remedy, so an ambient transaction is refused up front with
     * {@see NestedInitiationTransactionException}.
     *
     * THE CLOCK. Each phase reads the clock FRESHLY when the caller injected
     * none, because the provider round trip takes real time and a link whose
     * TTL lapses during it must not be handed a URL by Phase B (review round 1,
     * Important 2). An INJECTED `$now` deliberately governs BOTH phases, so a
     * test that pins a moment gets one deterministic instant everywhere rather
     * than a frozen Phase A and a live Phase B.
     *
     * @return array{checkoutUrl: string}
     * @throws PaymentLinkException `payment_link_not_payable`,
     *     `payment_link_rate_limited`, `return_url_unavailable`,
     *     `checkout_manual`, `checkout_url_missing`, `checkout_url_untrusted`,
     *     or `checkout_initiation_failed`. Those are the complete set of
     *     PAYER-FACING outcomes: no provider exception ever escapes and no
     *     refusal carries a URL. Two non-payer throwables remain possible and
     *     are deliberately untyped -- {@see NestedInitiationTransactionException}
     *     for the caller bug above, and whatever the database driver raises for
     *     a genuine infrastructure failure (a lost connection, a deadlock). Both
     *     are proven token-free by `PaymentLinkInitiationTest`'s frame-scrub
     *     ratchet, but neither is a state a controller can render: Task 8 must
     *     map any unknown throwable from this method to a BODILESS 500.
     * @throws NestedInitiationTransactionException when a transaction is
     *     already open on the caller's connection
     */
    public function initiateByToken(
        ApplicationContext $context,
        string $rawToken,
        ?\DateTimeImmutable $now = null
    ): array {
        // Shape gate BEFORE any database work, same as `resolveByToken()`.
        if (!self::isWellFormedToken($rawToken)) {
            $rawToken = self::REDACTED_TOKEN;

            throw PaymentLinkException::linkNotPayable();
        }

        $tokenHash = self::hashToken($rawToken);
        // The token has served its only purpose in this frame. Overwrite it
        // BEFORE any I/O and before any throw, so nothing below can put a live
        // bearer credential into a backtrace (see the class docblock).
        $rawToken = self::REDACTED_TOKEN;

        if (db($context)->transactionLevel() > 0) {
            throw NestedInitiationTransactionException::forInitiation();
        }

        $tenant = $this->tenants->tenantUuid($context);

        $claim = $this->claimInitiation($context, $tenant, $tokenHash, self::moment($now));
        $linkUuid = $claim['linkUuid'];
        $orderUuid = $claim['orderUuid'];

        // Nothing is locked and no transaction is open across this call.
        $initiation = $this->openProviderSession($context, $tenant, $linkUuid, $orderUuid);

        return [
            'checkoutUrl' => $this->confirmInitiation(
                $context,
                $tenant,
                $linkUuid,
                $orderUuid,
                $initiation,
                // FRESH unless the caller pinned one: the provider call just
                // consumed real time, and expiry is the one predicate whose
                // truth depends on the clock rather than on the row.
                self::moment($now)
            ),
        ];
    }

    /**
     * PHASE A: decide that this link may start a checkout, and pay for the
     * privilege out of its hourly budget -- all inside ONE transaction that
     * commits before any provider I/O begins.
     *
     * The token hash lookup at the top is an UNLOCKED POINTER read, and that is
     * safe by construction rather than by luck: `token_hash` is immutable for
     * the life of a row, so the uuid it yields is a stable handle, and EVERY
     * predicate is then decided against the row re-read under the lock. A
     * concurrent revoke racing this read simply loses to the locked re-read.
     *
     * Lock order is ORDER FIRST, THEN LINK -- the order this whole class shares,
     * which is what keeps mint, revoke, and both initiation phases from
     * deadlocking against each other.
     *
     * @return array{linkUuid: string, orderUuid: string} the captured identity
     * @throws PaymentLinkException `payment_link_not_payable` or
     *     `payment_link_rate_limited`
     */
    private function claimInitiation(
        ApplicationContext $context,
        string $tenant,
        string $tokenHash,
        \DateTimeImmutable $now
    ): array {
        /** @var array{linkUuid: string, orderUuid: string} */
        return db($context)->transaction(function () use ($context, $tenant, $tokenHash, $now): array {
            $found = $this->links->findByTokenHash($context, $tenant, $tokenHash);
            if ($found === null) {
                throw PaymentLinkException::linkNotPayable();
            }

            $linkUuid = (string) $found['uuid'];
            $orderUuid = (string) $found['order_uuid'];

            $order = $this->orders->findByUuidForUpdate($context, $tenant, $orderUuid, includeDrafts: true);
            if ($order === null) {
                throw PaymentLinkException::linkNotPayable();
            }

            $link = $this->links->findByUuidForUpdate($context, $tenant, $linkUuid);
            if (!self::isPayable($link, $order, $now)) {
                throw PaymentLinkException::linkNotPayable();
            }

            // The window is claimed HERE: inside the transaction, under the
            // locks, and BEFORE the provider leg. `claimInitiationWindow()`
            // returns false for three reasons, but two of them cannot happen on
            // this path -- the link was just read under the order lock (so it
            // is neither unknown nor cross-tenant), and the same lock serializes
            // every other claimer (so the compare-and-set cannot lose). What is
            // left is the genuine rate refusal, which is why reporting it as one
            // is honest here and would not be from an unlocked caller.
            if (!$this->links->claimInitiationWindow($context, $tenant, $linkUuid, $now)) {
                throw PaymentLinkException::initiationRateLimited();
            }

            return ['linkUuid' => $linkUuid, 'orderUuid' => $orderUuid];
        });
    }

    /**
     * THE PROVIDER LEG, which runs with no transaction open and no row locks
     * held -- the reason initiation is two-phase at all.
     *
     * Order of operations is a contract, not an implementation detail: the
     * return/cancel URLs are resolved and VALIDATED before the collector is
     * touched, so a host with no payment-link return surface never causes a
     * provider session to exist at all. Design spec §2.2 is explicit that a
     * missing or invalid binding is a typed unavailable outcome and must NOT
     * fall back to the guest-cookie order return route or to a gateway-global
     * callback -- either would land the payer on a page they cannot be
     * authorized for, after their money had already moved.
     *
     * The payable is the ORDINARY `commerce_order` reference, built exactly as
     * {@see CheckoutService::initiatePayment()} builds it (same type, same id,
     * same amount/currency/description, same `email` + `callback_url` +
     * `cancel_url` metadata), so a gateway cannot tell a payment-link session
     * from a storefront one and no reconciliation path needs a special case.
     * The one addition is `link_uuid`, so a provider webhook or a support
     * question can be traced back to the link. NEVER the raw token: a payable
     * is stored in a gateway dashboard and replayed through webhooks, so a
     * bearer credential in its metadata would be a credential handed to every
     * intermediary.
     *
     * It returns the collector's ANSWER, not a URL: design spec §2.2 puts the
     * `status='ok'`-plus-checkout-URL validation in Phase B, AFTER the predicate
     * recheck, so that a link revoked mid-call is refused as non-payable rather
     * than being told anything about what the provider said.
     *
     * @throws PaymentLinkException `payment_link_not_payable`,
     *     `return_url_unavailable`, or `checkout_initiation_failed`
     */
    private function openProviderSession(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        string $orderUuid
    ): PaymentInitiation {
        // "the live order" (design spec §2.2) -- read WITHOUT a lock, because
        // no transaction may span the provider call. Phase B is what makes this
        // safe: it re-decides under lock before anything is exposed.
        $order = $this->orders->findByUuid($context, $tenant, $orderUuid, includeDrafts: true);
        if ($order === null) {
            throw PaymentLinkException::linkNotPayable();
        }

        // The `email` key is OMITTED rather than sent empty when the order has
        // none (review round 1, minor 5). Unlike a storefront order, an
        // admin/walk-in order legitimately has no email at all -- and payvia's
        // collector reads `metadata['email'] ?? null`, so an ABSENT key means
        // exactly "no payer email" while an empty STRING is an invalid address
        // that a gateway rejects, turning a supportable order into an
        // undiagnosable `checkout_initiation_failed`.
        $email = trim((string) ($order['email'] ?? ''));
        $identity = $email === '' ? [] : ['email' => $email];

        $payable = new PayableReference(
            OrderPayable::TYPE,
            (string) $order['uuid'],
            (int) $order['grand_total'],
            (string) $order['currency'],
            'Order ' . (string) $order['order_number'],
            $identity + ['link_uuid' => $linkUuid] + $this->paymentLinkReturnMetadata($context, $linkUuid)
        );

        try {
            return ($this->collector ?? new ManualPaymentCollector())->initiate($context, $payable);
        } catch (\Throwable) {
            // Payvia 2.6's ensure-live raises TYPED exceptions for repeat
            // initiations it cannot renew, and a gateway HTTP client can raise
            // anything at all. Both are the same fact to a payer: no checkout
            // right now. The throwable is swallowed rather than chained --
            // its message and its backtrace may quote the payable metadata,
            // the return URLs, or a provider reference, and this exception is
            // on its way to an anonymous browser.
            throw PaymentLinkException::checkoutInitiationFailed();
        }
    }

    /**
     * The host's return/cancel routes, resolved and validated into the exact
     * metadata keys `CheckoutService` already uses.
     *
     * Validation is {@see HttpsUrl::isAbsoluteHttps()} and nothing else -- the
     * SHARED definition, deliberately not a second strictness invented here.
     * It permits a query string on purpose: a signed return route carries its
     * signature there (design spec §2.3). It is NOT the public-link URL check
     * ({@see self::isValidPublicUrl()}), which is far stricter because that URL
     * carries a bearer token and this one does not.
     *
     * A provider that throws is caught for the same reason `composePublicUrl()`
     * catches: third-party code's exception message is not this engine's to
     * forward.
     *
     * @return array{callback_url: string, cancel_url: string}
     * @throws PaymentLinkException `return_url_unavailable`
     */
    private function paymentLinkReturnMetadata(ApplicationContext $context, string $linkUuid): array
    {
        if ($this->returnUrls === null) {
            throw PaymentLinkException::returnUrlUnavailable();
        }

        try {
            $urls = $this->returnUrls->urlsFor($context, $linkUuid);
        } catch (\Throwable) {
            throw PaymentLinkException::returnUrlUnavailable();
        }

        if (!is_array($urls)) {
            throw PaymentLinkException::returnUrlUnavailable();
        }

        // Widened DELIBERATELY. The contract's return type says both keys are
        // present and are strings; a HOST IMPLEMENTATION is untrusted code that
        // can return anything a `mixed`-shaped array can hold, and the checks
        // below are the ones that actually protect a browser redirect. Without
        // this the analyser would call those checks redundant and we would end
        // up deleting the only thing standing between a misconfigured host and
        // an open redirect.
        /** @var array<string,mixed> $candidates */
        $candidates = $urls;

        $resolved = [];
        foreach (['return' => 'callback_url', 'cancel' => 'cancel_url'] as $key => $metadataKey) {
            $url = $candidates[$key] ?? null;
            if (!is_string($url) || !HttpsUrl::isAbsoluteHttps($url)) {
                throw PaymentLinkException::returnUrlUnavailable();
            }

            $resolved[$metadataKey] = $url;
        }

        /** @var array{callback_url: string, cancel_url: string} $resolved */
        return $resolved;
    }

    /**
     * Classify the collector's answer into ONE trusted URL or ONE typed
     * refusal. There is no partial credit: a payment link's entire purpose is
     * the redirect, so a `reference`-only payload is as unusable here as an
     * empty one, even though the storefront's own view model can render it.
     *
     * The three refusals are kept apart because they are three different bugs
     * for whoever has to fix the gateway, even though they are the same
     * non-event for the payer.
     *
     * @throws PaymentLinkException `checkout_manual`, `checkout_url_missing`,
     *     `checkout_url_untrusted`, or `checkout_initiation_failed`
     */
    private static function checkoutUrlFrom(PaymentInitiation $initiation): string
    {
        if ($initiation->status === self::STATUS_MANUAL) {
            throw PaymentLinkException::checkoutManual();
        }

        // The contract defines exactly `ok` and `manual`. Anything else is a
        // collector regression, and a payment link must fail closed on one.
        if ($initiation->status !== self::STATUS_OK) {
            throw PaymentLinkException::checkoutInitiationFailed();
        }

        // The SHARED key list (see the const's docblock): the storefront's own
        // payment view model and a payment-link redirect must find the same URL
        // in the same payload by the same rule.
        $url = null;
        foreach (CheckoutPresentation::CANDIDATE_URL_KEYS as $key) {
            $candidate = $initiation->payload[$key] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $url = $candidate;
                break;
            }
        }

        if ($url === null) {
            throw PaymentLinkException::checkoutUrlMissing();
        }

        // The same absolute-HTTPS definition the return URLs are held to. A
        // `javascript:`, `data:`, protocol-relative, or relative URL reaching a
        // browser redirect is an open-redirect/XSS primitive, and a plain-http
        // one strips the payer's transport security mid-payment.
        if (!HttpsUrl::isAbsoluteHttps($url)) {
            throw PaymentLinkException::checkoutUrlUntrusted();
        }

        // PLUS one check the shared definition deliberately does NOT make
        // (review round 1, minor 3). `https://psp.example.com@evil.example.com/x`
        // is absolute HTTPS to a parser and reads as the PSP's domain to a
        // human: the real host is `evil.example.com` and everything before the
        // `@` is userinfo. That is a textbook phishing primitive, and this is
        // the one URL here that a BROWSER IS SENT TO while the payer is being
        // asked for card details. No payment provider needs userinfo in a
        // hosted-checkout URL, so it is refused.
        //
        // Scoped to THIS branch on purpose: {@see HttpsUrl} stays permissive
        // for the return/cancel URLs, which are the host's own signed routes
        // (§2.3) rather than a third party's, and tightening the shared
        // definition would reject correct hosts.
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            throw PaymentLinkException::checkoutUrlUntrusted();
        }

        return $url;
    }

    /**
     * PHASE B: the last word before a URL is exposed.
     *
     * Relocks the SAME order then the SAME link BY UUID (never by token again --
     * this frame has no token, by design) and rechecks EVERY predicate Phase A
     * checked, because the locks were released for the whole provider call and
     * anything could have committed in that window: a revoke, an admin cancel,
     * an OrderPaid consuming the link, or {@see self::resolveByToken()}'s own
     * unlocked lazy expiry.
     *
     * A refusal here THROWS, which rolls this transaction back, so the exposure
     * stamp and the audit row are never written. That is the design spec's
     * "an attempt created during that race remains server-side and no URL is
     * exposed": the provider may well hold a session, but nobody was ever told
     * where it is.
     *
     * The stamp and the audit row commit together with the recheck, so
     * `provider_session_issued_at` means exactly "a checkout URL for this link
     * was handed to somebody" -- which is the question Task 8's exposure guard
     * has to answer.
     *
     * The collector's answer is validated HERE rather than at the call site,
     * and AFTER the predicate recheck, exactly as design spec §2.2 orders it: a
     * link that stopped being payable mid-call is refused as non-payable and
     * learns nothing about what the provider said.
     *
     * `$now` is a FRESH reading unless the caller injected one (review round 1,
     * Important 2). Expiry is the one predicate whose truth lives in the clock
     * rather than in the row: re-reading the link under lock proves nothing
     * about a TTL that lapsed while the provider was thinking. Reusing Phase A's
     * timestamp would both expose a URL for an already-dead link and back-date
     * `provider_session_issued_at` by the whole duration of the provider call.
     *
     * @return string the validated absolute-HTTPS checkout URL
     * @throws PaymentLinkException `payment_link_not_payable`,
     *     `checkout_manual`, `checkout_url_missing`, `checkout_url_untrusted`,
     *     or `checkout_initiation_failed`
     */
    private function confirmInitiation(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        string $orderUuid,
        PaymentInitiation $initiation,
        \DateTimeImmutable $now
    ): string {
        /** @var string */
        return db($context)->transaction(
            function () use ($context, $tenant, $linkUuid, $orderUuid, $initiation, $now): string {
                // ORDER first, then link -- the same order Phase A took them in.
                $order = $this->orders->findByUuidForUpdate($context, $tenant, $orderUuid, includeDrafts: true);
                if ($order === null) {
                    throw PaymentLinkException::linkNotPayable();
                }

                $link = $this->links->findByUuidForUpdate($context, $tenant, $linkUuid);
                if (!self::isPayable($link, $order, $now)) {
                    throw PaymentLinkException::linkNotPayable();
                }

                $checkoutUrl = self::checkoutUrlFrom($initiation);

                $this->links->stampProviderSessionIssued($context, $tenant, $linkUuid, $now);
                $this->orders->recordEvent(
                    $context,
                    $orderUuid,
                    PaymentLinkEvents::INITIATED,
                    ['link_uuid' => $linkUuid]
                );

                return $checkoutUrl;
            }
        );
    }

    /**
     * The FULL payability predicate, in ONE place so Phase A and Phase B cannot
     * drift: an existing link that is still `active`, whose TTL has not passed,
     * on an order that is still awaiting payment.
     *
     * The expiry boundary is EXCLUSIVE, matching
     * {@see self::applyLazyTransitions()} and
     * {@see PaymentLinkRepository::guardRelevantLinks()}: at the stamp itself
     * the link has lapsed.
     *
     * Note what it does NOT do: it never TRANSITIONS anything. Initiation is a
     * read-side decision about whether to proceed; the lazy `expired`/`consumed`
     * transitions belong to the display paths, and doing them here would mean an
     * anonymous initiation could write to the link table on a refusal.
     *
     * @param array<string,mixed>|null $link
     * @param array<string,mixed> $order
     */
    private static function isPayable(?array $link, array $order, \DateTimeImmutable $now): bool
    {
        if ($link === null) {
            return false;
        }

        if ((string) $link['status'] !== PaymentLinkRepository::STATUS_ACTIVE) {
            return false;
        }

        if (self::normalizeStamp((string) $link['expires_at']) <= self::stamp($now)) {
            return false;
        }

        return (string) $order['status'] === self::PAYABLE_STATUS;
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
