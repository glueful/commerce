<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Events;

/**
 * The payment link's audit vocabulary (payment-links Task 6, design spec §2.2).
 *
 * These are `commerce_order_events.type` values -- ROWS, not dispatched
 * lifecycle events, exactly like {@see DraftOrderEvents}. Issuing or revoking a
 * link is operator bookkeeping about how an EXISTING order may be paid; it is
 * not a commerce fact, so it must not wake the transactional-mail listener, the
 * seller-webhook outbox, or the marketplace fan-out. The payment itself is
 * still audited by the ordinary lifecycle rows when it happens.
 *
 * Writers, all inside {@see \Glueful\Extensions\Commerce\Orders\PaymentLinkService}:
 *  - {@see self::MINTED}  -- one row per link actually inserted, written INSIDE
 *    the mint transaction, so a rolled-back mint audits nothing.
 *  - {@see self::REVOKED} -- one row per link actually transitioned out of
 *    `active`. A REGENERATE therefore writes both (revoked-then-minted for the
 *    superseded link and its replacement), and an explicit revoke of an order
 *    with no active link writes nothing at all, which is what makes the
 *    operation idempotent in the trail as well as in the table.
 *  - {@see self::INITIATED} -- one row per checkout session actually EXPOSED to
 *    a payer, written inside Task 7's Phase B transaction alongside the
 *    `provider_session_issued_at` stamp. An attempt that reached the provider
 *    but was then refused by Phase B (a revoke or cancel that committed while
 *    the provider call was in flight) audits nothing here, exactly as it
 *    exposes no URL: the two facts commit or roll back together. There is no
 *    actor -- the payer is anonymous by construction, so `actor_uuid` stays
 *    null and the row records only which link opened a session, and when.
 *
 * ## The payload is `link_uuid` and nothing else
 *
 * Never the token, never its hash. The point of the trail is "who issued or
 * killed which link, and when"; the actor comes from `actor_uuid` and the time
 * from `created_at`, so the payload only has to name the link. Putting the hash
 * in would create a second, unmanaged copy of the lookup key in a table with a
 * different retention story and no unique constraint -- and putting the raw
 * token in would defeat the entire hashed-custody design in one line.
 * `PaymentLinkServiceTest`'s egress ratchet dumps this table and asserts the
 * token is absent.
 *
 * All are recorded with the default `internal` visibility: the customer's own
 * payment-link page is served from the link itself, never from this trail.
 */
final class PaymentLinkEvents
{
    public const MINTED = 'payment_link_minted';
    public const REVOKED = 'payment_link_revoked';
    public const INITIATED = 'payment_link_initiated';
}
