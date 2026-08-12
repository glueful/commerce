<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Payment-links Task 5 (design spec §2.2, first bullet): `commerce_payment_links`
 * -- the hashed custody record for an admin-minted, customer-facing payment link.
 *
 * A brand-new table with nothing existing to fold into, so `up()` is a single
 * `hasTable()`-guarded `createTable` and `down()` a single
 * `dropTableIfExists()`. That keeps this migration safe to re-run against an
 * already-migrated database, exactly like every other migration in this
 * codebase (test helpers across this suite freely re-run the full migration
 * list against a persistent, shared PostgreSQL fixture database).
 *
 * WHAT IS STORED, AND WHAT IS NOT:
 *  - `token_hash` (vc64) is the SHA-256 hex digest of the raw bearer token.
 *    The raw token exists exactly once, in the mint call's return value, and
 *    never reaches this table, this repository, a query log, or an exception
 *    trace -- see {@see \Glueful\Extensions\Commerce\Orders\PaymentLinkRepository},
 *    whose public surface has no raw-token parameter at all. NOT NULL, for the
 *    same reason `status` carries no default: the "fail loudly rather than mint
 *    a fail-open credential" argument applies at least as strongly to the hash
 *    itself. (A NULL hash would additionally be exempt from the unique below
 *    under ANSI NULL semantics, so any number of credential-less rows could
 *    accumulate unnoticed.)
 *  - `status` is the CLOSED domain `active|revoked|expired|consumed`. It is
 *    NOT NULL with NO STANDING DEFAULT, deliberately. Migration 022's
 *    `origin`/`fulfillment_mode` had to keep their defaults because a large
 *    body of pre-existing fixtures inserted `commerce_orders` rows directly
 *    and predated those columns; this table is new, has no such legacy, and a
 *    writer that forgets `status` must fail LOUDLY rather than silently mint
 *    an ACTIVE payment credential. (The domain itself is enforced by the
 *    repository's constants and the service layer, not a CHECK constraint --
 *    consistent with every other closed-status column in this schema.)
 *  - `expires_at` is NOT NULL: a payment link with no TTL is not a thing this
 *    system can mint.
 *  - `initiation_window_started_at` / `initiation_count` are the FIXED UTC
 *    ONE-HOUR rate window. `initiation_count` keeps its `NOT NULL DEFAULT 0`
 *    because that default is exact truth for a freshly minted link (spec
 *    §2.2 states it explicitly), not a fail-open guess. The reset+increment is
 *    a compare-and-set taken under the link row lock inside the caller's
 *    transaction -- see `PaymentLinkRepository::claimInitiationWindow()`.
 *  - `provider_session_issued_at` is the PERMANENT provider-session exposure
 *    record. It is stamped once, never cleared, and survives every terminal
 *    status transition: the expiry/cancel guard's whole point is that a
 *    revoked or expired link whose checkout session was already handed to a
 *    customer still blocks automatic cancellation.
 *
 * INDEXES:
 *  - UNIQUE `(tenant_uuid, uuid)`: the ROW IDENTITY guarantee, not a
 *    performance index. `PaymentLinkRepository::findByUuid()` and its locking
 *    sibling both resolve with `first()`, and `revoke()`/`consume()`/
 *    `expire()`/`claimInitiationWindow()`/`stampProviderSessionIssued()` all
 *    address a link BY uuid -- so without this constraint a duplicate
 *    `(tenant, uuid)` would silently resolve to an arbitrary row and those
 *    mutations would act on an arbitrary one of them, with no error anywhere.
 *    Every other uuid-bearing table in this schema (migrations 004, 006,
 *    011-020) already carries it; this table would otherwise be the sole
 *    outlier. The spec's §2.2 index list enumerates the FEATURE lookup indexes
 *    below; its only prohibition is the partial unique (Ruling 7), which this
 *    is not.
 *  - UNIQUE `(tenant_uuid, token_hash)`: the resolve path's only lookup key.
 *    Tenant-scoped rather than global, so a hash collision across tenants is
 *    not this table's problem and cross-tenant probing is impossible.
 *  - `(tenant_uuid, order_uuid, status)`: the per-order current-link lookup
 *    (mint, revoke, `mode=current` matching, terminal fan-out).
 *  - `(tenant_uuid, provider_session_issued_at, order_uuid)`: the
 *    issued-session lookup the §2.2 expiry/cancel guard PREFILTERS read. That
 *    query asks "which of this tenant's orders carry ANY historical link with
 *    `provider_session_issued_at IS NOT NULL`", so tenant leads, the nullable
 *    stamp is the selective seek predicate, and `order_uuid` trails as the
 *    projected column -- a covering index for the prefilter and still usable
 *    for the per-order guard read. The (tenant, order, status) index above
 *    serves the prefilter's other branch (active + unexpired).
 *
 * There is deliberately NO partial/filtered unique index over
 * `(tenant_uuid, order_uuid)` where `status = 'active'`. The one-active-link-
 * per-order authority is Ruling 7 -- TRANSACTIONAL (lock the order, then the
 * link, inside one transaction). Moving it into the schema would turn a
 * legitimate service-side race into a raw driver error instead of a typed
 * conflict, and would additionally be non-portable across the drivers this
 * framework targets. `Migrations\PaymentLinkSchemaTest` pins both halves: two
 * ACTIVE rows for one order coexist at the database level, and no index on
 * this table carries a `WHERE` clause.
 *
 * No foreign key to `commerce_orders`: no foreign keys are declared anywhere
 * in the commerce migrations (see `Tenancy\CommerceTenantPurge`'s docblock).
 * The `order_uuid` reference is logical, and tenant purge sweeps this table by
 * its OWN `tenant_uuid` column rather than through a parent join -- it is
 * registered in `Support\DiagnosticsReport::commerceTables()` in this same
 * task and therefore reached by `tenantTables()` (and so by both
 * `CommerceTenantPurge` and `TenantAdopter`) by omission from that method's
 * child-table exclusion list.
 */
final class CreatePaymentLinksTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('commerce_payment_links')) {
            return;
        }

        $schema->createTable('commerce_payment_links', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');
            $table->string('order_uuid', 12);
            // SHA-256 hex of the raw bearer token -- never the token itself.
            $table->string('token_hash', 64)->notNull();
            // Closed domain: active|revoked|expired|consumed. No default: see the
            // class docblock (a writer that forgets this must fail, not fail open).
            $table->string('status', 16)->notNull();
            $table->timestamp('expires_at')->notNull();
            $table->string('created_by', 12);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Fixed UTC one-hour rate window, claimed under the link row lock.
            $table->timestamp('initiation_window_started_at')->nullable();
            $table->integer('initiation_count')->notNull()->default(0);
            // Permanent provider-session exposure record -- stamped once, never cleared.
            $table->timestamp('provider_session_issued_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_uuid', 'uuid'], 'commerce_payment_links_tenant_uuid_unique');
            $table->unique(['tenant_uuid', 'token_hash'], 'commerce_payment_links_tenant_token_unique');
            $table->index(
                ['tenant_uuid', 'order_uuid', 'status'],
                'commerce_payment_links_order_status_index'
            );
            $table->index(
                ['tenant_uuid', 'provider_session_issued_at', 'order_uuid'],
                'commerce_payment_links_issued_session_index'
            );
        });
    }

    /**
     * Rollback destroys every payment link. That is the honest consequence of
     * removing the table: a link's hashed credential has no meaning without the
     * row, and there is nowhere else to preserve it. Operators must revoke
     * outstanding links (or accept that they stop resolving) before rolling
     * back. Nothing else in the schema references this table, so the drop needs
     * no ordering dance.
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_payment_links');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_payment_links table: hashed payment-link custody, closed status domain, '
            . 'fixed-UTC-hour initiation counter, and the provider-session exposure stamp.';
    }
}
