# Commerce Account Seams Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Commerce the two account-facing seams a storefront needs — a customer-safe guest-order claim and a tenant-scoped wishlist — then ship them in the single unpublished 1.8.0 alongside its batched catalog reads.

**Architecture:** The wishlist is a parent row per (tenant, user) with a revision lock plus positioned item rows, mirroring the address book's `ensureBook`/`claimBook` discipline — that lock is what makes the 100-item cap and the merge ordering hold under concurrency. `GuestOrderClaimService` wraps the race-safe `OrderRepository::linkGuestToUser()` with the proofs a customer-initiated claim requires. Claiming ships as **service seams only, never HTTP**: Commerce has no verified-email authority, so the calling application — which owns the verified-account context — invokes it server-side.

**Tech Stack:** PHP 8.3+, Glueful framework 1.73.0, PHPUnit 10, SQLite in-memory harness (`CommerceTestCase`) with pgsql-gated race tests, PSR-12.

## Global Constraints

- **One release, not two.** These seams fold into the **unpublished** 1.8.0 whose batched catalog reads already sit under `## [Unreleased]` (last published tag: `v1.7.0`). Do not create a 1.9.0.
- **Commerce stays host-agnostic.** No listener for any application's events, no reference to any consuming app, storefront, theme, or pack.
- **Claiming is a service seam, not a route.** Commerce cannot verify an email: `JwtAuthenticationProvider` populates the `'user'` attribute with uuid/session fields only — no email — and `glueful/users` drops `email_verified_at` when building `UserIdentity`. A Commerce endpoint would therefore either fail closed always or trust a client-asserted address. **Ship no claim controller and no claim routes.**
- **Every claim failure is the same non-revealing 404** — unknown order, wrong tenant, owned by someone else, bad guest token, email mismatch.
- **Both proofs are required for a token claim:** a valid guest credential **and** a normalized-email match.
- **Every wishlist growth path runs under the parent claim.** `ensureList()` outside the transaction, `claimList()` inside it, then re-read and mutate. A count-then-insert without that lock lets concurrent adds exceed the cap.
- **Ordering is explicit, never implied by timestamps.** `position` ascending is the display order: `add()` inserts at the front (`min - 1`), `import()` appends at the back (`max + 1`, …). Sorting by `created_at` would place freshly imported rows *before* the account's own older items — the opposite of the merge rule.
- **Wishlist cap is 100 per (tenant, user), refused explicitly** — never silent eviction.
- **Availability is checked before a product can consume a cap slot**, so an arbitrary uuid cannot fill the list invisibly.
- **Only a verified unique-conflict becomes an idempotent no-op.** Any other database failure must surface, never be reported as "already saved".
- **Both new tables join the tenancy inventory** (`DiagnosticsReport::commerceTables()`), which feeds `TenantTableRegistry` registration, adoption, diagnostics and purge at boot.
- **Identifiers are validated as exactly 12 alphanumeric characters**, not merely length-bounded.
- **Actor resolution is `$request->attributes->get('user')`**, matching `OrderController::mine()` and `AccountAddressController` — never `auth.user`.
- **Quality gates per commit:** `vendor/bin/phpunit`, `vendor/bin/phpcs --standard=PSR12 src tests`, PHPStan.
- **Commit cadence:** commit at Tasks 4, 6 and 7 only. No AI/assistant attribution anywhere.

---

## File Structure

**New — `src/Wishlist/`:**

| File | Responsibility |
|---|---|
| `WishlistRepository.php` | Rows and the parent lock: `ensureList`/`claimList`, positioned insert, list/count/remove. No business rules. |
| `WishlistService.php` | Availability, cap-under-lock, position assignment, the import merge. |
| `WishlistImportResult.php` | Imported / unavailable / overflow uuid lists. |

**New — `src/Orders/`:** `GuestOrderClaimService.php` — both claim operations. No HTTP.

**New elsewhere:**
- `migrations/020_CreateWishlistTables.php` (two tables)
- `src/Http/Storefront/AccountWishlistController.php`
- `src/Http/DTOs/WishlistItemData.php`, `src/Http/DTOs/WishlistImportData.php`
- `tests/Integration/Wishlist/` (schema, repository, service, concurrency + a pgsql child fixture)
- `tests/Integration/Orders/GuestOrderClaimTest.php`
- `tests/Integration/Http/AccountWishlistHttpTest.php`

**Modified:** `src/Support/DiagnosticsReport.php` (inventory), `src/CommerceServiceProvider.php`, `routes.php`, `tests/Support/CommerceTestCase.php`, tenancy adoption/purge tests, `CHANGELOG.md`.

---

## Task 1: Wishlist tables and tenancy inventory

**Files:**
- Create: `migrations/020_CreateWishlistTables.php`
- Modify: `src/Support/DiagnosticsReport.php`, `tests/Support/CommerceTestCase.php`
- Test: `tests/Integration/Wishlist/WishlistSchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `commerce_wishlists` (`id`, `uuid`, `tenant_uuid`, `user_uuid`, `revision`, timestamps; unique `(tenant_uuid, user_uuid)`) and `commerce_wishlist_items` (`id`, `uuid`, `tenant_uuid`, `user_uuid`, `product_uuid`, `position`, timestamps; unique `(tenant_uuid, user_uuid, product_uuid)`; unique `(tenant_uuid, uuid)`; index `(tenant_uuid, user_uuid, position)`). Both listed in `DiagnosticsReport::commerceTables()`.

**Why a parent row:** the cap and the merge order are guarantees about a *set*, and a set has no row to lock. The address book already solved this — `ensureBook()` + `claimBook()` — and the same shape is what serializes two concurrent saves here.

**Why the inventory matters:** `CommerceServiceProvider::boot()` registers `DiagnosticsReport::tenantTables()` with `TenantTableRegistry`. A tenant-scoped table missing from that list is invisible to tenant registration, adoption, diagnostics and purge — it would silently survive a tenant teardown.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class WishlistSchemaTest extends CommerceTestCase
{
    public function testOneProductMayBeSavedOncePerUser(): void
    {
        $db = $this->connection->getPDO();
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000001', '', 'user00000001', 'prod00000001', 0, '2026-07-29 10:00:00')"
        );

        $this->expectException(\PDOException::class);
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000002', '', 'user00000001', 'prod00000001', 1, '2026-07-29 10:00:01')"
        );
    }

    public function testTheSameProductMaySitInTwoDifferentUsersLists(): void
    {
        $db = $this->connection->getPDO();
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000003', '', 'user00000001', 'prod00000002', 0, '2026-07-29 10:00:00')"
        );
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000004', '', 'user00000002', 'prod00000002', 0, '2026-07-29 10:00:00')"
        );

        $count = (int) $db->query(
            "SELECT COUNT(*) FROM commerce_wishlist_items WHERE product_uuid = 'prod00000002'"
        )->fetchColumn();

        self::assertSame(2, $count);
    }

    public function testOneParentListPerUser(): void
    {
        $db = $this->connection->getPDO();
        $db->exec(
            "INSERT INTO commerce_wishlists (uuid, tenant_uuid, user_uuid, revision)
             VALUES ('wlst00000001', '', 'user00000001', 0)"
        );

        $this->expectException(\PDOException::class);
        $db->exec(
            "INSERT INTO commerce_wishlists (uuid, tenant_uuid, user_uuid, revision)
             VALUES ('wlst00000002', '', 'user00000001', 0)"
        );
    }

    public function testBothTablesAreInTheTenancyInventory(): void
    {
        // The inventory drives TenantTableRegistry registration, adoption, diagnostics and
        // purge. A tenant table missing here survives a tenant teardown unnoticed.
        $tables = DiagnosticsReport::commerceTables();

        self::assertContains('commerce_wishlists', $tables);
        self::assertContains('commerce_wishlist_items', $tables);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistSchemaTest.php`
Expected: FAIL — `no such table: commerce_wishlist_items`.

- [ ] **Step 3: Write the migration**

`migrations/020_CreateWishlistTables.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Account-backed wishlist (accounts design spec §11): a parent list row per
 * (tenant, user) plus positioned item rows. Genuinely new tables, first published
 * in Commerce 1.8.0 — never a fold into an earlier migration.
 *
 * The parent exists to be LOCKED. The 100-item cap and the merge ordering are
 * guarantees about the whole set, and a set has no row to serialize on; this
 * mirrors `commerce_customer_address_books`, whose `revision` claim is what makes
 * two concurrent writes to one account line up instead of interleaving.
 *
 * `position` (not `created_at`) is the display order, ascending. Saves go to the
 * front, imports append to the back. Ordering by timestamp would put freshly
 * imported rows ahead of the account's own older items — the opposite of the rule.
 *
 * Commerce stores only the tenant-scoped commerce fact; identity lives in the host.
 */
final class CreateWishlistTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_wishlists')) {
            $schema->createTable('commerce_wishlists', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('user_uuid', 12);
                // Claim counter: the affected-row-checked bump every growth path takes.
                $table->bigInteger('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['tenant_uuid', 'user_uuid'], 'commerce_wishlists_tenant_user_unique');
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_wishlists_tenant_uuid_unique');
            });
        }

        if (!$schema->hasTable('commerce_wishlist_items')) {
            $schema->createTable('commerce_wishlist_items', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('user_uuid', 12);
                $table->string('product_uuid', 12);
                // Display order, ascending. Negative values are expected: saves take
                // `min - 1` so a new item leads without renumbering the whole list.
                $table->bigInteger('position')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Saving the same product twice is idempotent, not a duplicate row.
                $table->unique(
                    ['tenant_uuid', 'user_uuid', 'product_uuid'],
                    'commerce_wishlist_items_tenant_user_product_unique'
                );
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_wishlist_items_tenant_uuid_unique');
                $table->index(
                    ['tenant_uuid', 'user_uuid', 'position'],
                    'commerce_wishlist_items_tenant_user_position_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_wishlist_items');
        $schema->dropTableIfExists('commerce_wishlists');
    }

    public function getDescription(): string
    {
        return 'Creates the account-backed wishlist tables (commerce_wishlists, commerce_wishlist_items).';
    }
}
```

- [ ] **Step 4: Add both tables to the tenancy inventory**

In `src/Support/DiagnosticsReport.php`, append to the `commerceTables()` list:

```php
            'commerce_wishlists',
            'commerce_wishlist_items',
```

- [ ] **Step 5: Register the migration in the test harness**

In `tests/Support/CommerceTestCase.php`, append to `MIGRATIONS`:

```php
        \Glueful\Extensions\Commerce\Database\Migrations\CreateWishlistTables::class,
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistSchemaTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Seed both tables in the purge test**

Listing the tables is not coverage. `CommerceTenantPurgeTest::seedTenant()` seeds a fixed set,
and the assertion loop expects `1` for seeded tables and `0` for everything else — so a newly
listed table passes as an empty table and proves nothing.

In `tests/Integration/Tenancy/CommerceTenantPurgeTest.php`, add to `seedTenant()` (following the
existing `Utils::generateNanoID()` + `$this->connection->table(...)->insert(...)` shape):

```php
        $wishlistUuid = Utils::generateNanoID();
        $wishlistItemUuid = Utils::generateNanoID();

        $this->connection->table('commerce_wishlists')->insert([
            'uuid' => $wishlistUuid,
            'tenant_uuid' => $tenant,
            'user_uuid' => 'user' . substr($tenant, 0, 8),
            'revision' => 0,
        ]);
        $this->connection->table('commerce_wishlist_items')->insert([
            'uuid' => $wishlistItemUuid,
            'tenant_uuid' => $tenant,
            'user_uuid' => 'user' . substr($tenant, 0, 8),
            'product_uuid' => $productUuid,
            'position' => 0,
        ]);
```

and add both table names to the `$seededTenantTables` list in
`testPurgeRemovesOnlyTargetTenantRowsAcrossEveryTenantTable()`, so the purge is asserted to
delete exactly one row from each for tenant A — and, through the existing tenant-B assertions,
to leave tenant B's rows untouched.

- [ ] **Step 8: Prove sentinel adoption rekeys both tables**

In `tests/Integration/Tenancy/TenantAdopterTest.php`, seed a parent list row and an item row
under the sentinel tenant, run the adopter, and assert BOTH rows now carry the target tenant.
Adoption that rekeys the parent but not its items would leave a list whose contents belong to a
different tenant — invisible until a visitor's wishlist renders someone else's products.

```php
    public function testAdoptionRekeysWishlistParentsAndItems(): void
    {
        $this->connection->table('commerce_wishlists')->insert([
            'uuid' => 'wlstadopt001',
            'tenant_uuid' => '',
            'user_uuid' => 'useradopt001',
            'revision' => 0,
        ]);
        $this->connection->table('commerce_wishlist_items')->insert([
            'uuid' => 'wishadopt001',
            'tenant_uuid' => '',
            'user_uuid' => 'useradopt001',
            'product_uuid' => 'prodadopt001',
            'position' => 0,
        ]);

        $this->adopt('tenantadopt1');   // the helper this suite already uses

        foreach (['commerce_wishlists', 'commerce_wishlist_items'] as $table) {
            $tenants = $this->connection->table($table)->select(['tenant_uuid'])->get();
            self::assertSame(
                ['tenantadopt1'],
                array_values(array_unique(array_column($tenants, 'tenant_uuid'))),
                $table . ' was not rekeyed by adoption'
            );
        }
    }
```

Match the suite's existing adopter invocation and fixture helpers rather than the placeholder
`adopt()` above if they differ.

- [ ] **Step 9: Run the tenancy suites**

Run: `vendor/bin/phpunit tests/Integration/Tenancy tests/Unit/Tenancy`
Expected: PASS, with the purge test now deleting one row from each wishlist table for tenant A
and the adoption test rekeying both.

---

## Task 2: Wishlist repository with the parent lock

**Files:**
- Create: `src/Wishlist/WishlistRepository.php`
- Test: `tests/Integration/Wishlist/WishlistRepositoryTest.php`

**Interfaces:**
- Consumes: the tables from Task 1.
- Produces:
  - `ensureList(ApplicationContext $context, string $tenant, string $userUuid): void`
  - `hasList(ApplicationContext $context, string $tenant, string $userUuid): bool`
  - `claimList(ApplicationContext $context, string $tenant, string $userUuid): bool`
  - `forUser(ApplicationContext $context, string $tenant, string $userUuid): array` — position ascending
  - `productUuidsForUser(...): list<string>`
  - `countForUser(...): int`
  - `frontPosition(...): int` — `min(position)`, or `0` when empty
  - `backPosition(...): int` — `max(position)`, or `0` when empty
  - `insertAt(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid, int $position): bool`
  - `has(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid): bool`
  - `remove(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;

final class WishlistRepositoryTest extends CommerceTestCase
{
    private function repository(): WishlistRepository
    {
        return new WishlistRepository();
    }

    public function testEnsureListIsIdempotentAndClaimBumpsTheRevision(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->ensureList($this->context, '', 'user00000001');

        $rows = (int) $this->connection->getPDO()->query(
            "SELECT COUNT(*) FROM commerce_wishlists WHERE user_uuid = 'user00000001'"
        )->fetchColumn();
        self::assertSame(1, $rows);

        self::assertTrue($repo->claimList($this->context, '', 'user00000001'));
        $revision = (int) $this->connection->getPDO()->query(
            "SELECT revision FROM commerce_wishlists WHERE user_uuid = 'user00000001'"
        )->fetchColumn();
        self::assertSame(1, $revision);
    }

    public function testClaimingAListThatDoesNotExistReportsFailure(): void
    {
        // The service must ensureList() first; a silent no-op claim would leave the growth
        // path unserialized while looking successful.
        self::assertFalse($this->repository()->claimList($this->context, '', 'user00000404'));
    }

    public function testItemsReadBackInPositionOrder(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000002', -1);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000003', 1);

        self::assertSame(
            ['prod00000002', 'prod00000001', 'prod00000003'],
            $repo->productUuidsForUser($this->context, '', 'user00000001')
        );
    }

    public function testFrontAndBackPositionsBoundTheList(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');

        self::assertSame(0, $repo->frontPosition($this->context, '', 'user00000001'));
        self::assertSame(0, $repo->backPosition($this->context, '', 'user00000001'));

        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 5);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000002', -3);

        self::assertSame(-3, $repo->frontPosition($this->context, '', 'user00000001'));
        self::assertSame(5, $repo->backPosition($this->context, '', 'user00000001'));
    }

    public function testInsertingAnAlreadySavedProductIsAnIdempotentFalse(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');

        self::assertTrue($repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0));
        self::assertFalse($repo->insertAt($this->context, '', 'user00000001', 'prod00000001', -1));
        self::assertSame(1, $repo->countForUser($this->context, '', 'user00000001'));
    }

    public function testARealDatabaseFailureIsNotReportedAsAlreadySaved(): void
    {
        // Swallowing every Throwable would turn an outage or schema drift into a cheerful
        // "already on your list" while nothing was written.
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $this->connection->getPDO()->exec('DROP TABLE commerce_wishlist_items');

        $this->expectException(\Throwable::class);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);
    }

    public function testADuplicateInsideAnOpenTransactionLeavesItUsable(): void
    {
        // The PostgreSQL failure mode this savepoint exists for: a unique violation aborts the
        // whole transaction, so both the duplicate re-check AND every later statement would
        // fail with "current transaction is aborted" if the insert were not isolated. SQLite is
        // more forgiving, which is exactly why this must also run against PostgreSQL (below).
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);

        $stillWorks = db($this->context)->transaction(function () use ($repo): bool {
            self::assertFalse($repo->insertAt($this->context, '', 'user00000001', 'prod00000001', -1));

            // The outer transaction must still accept writes after the swallowed duplicate.
            return $repo->insertAt($this->context, '', 'user00000001', 'prod00000002', -2);
        });

        self::assertTrue($stillWorks);
        self::assertSame(
            ['prod00000002', 'prod00000001'],
            $repo->productUuidsForUser($this->context, '', 'user00000001')
        );
    }

    public function testRemoveReportsWhetherARowWasDeleted(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);

        self::assertTrue($repo->remove($this->context, '', 'user00000001', 'prod00000001'));
        self::assertFalse($repo->remove($this->context, '', 'user00000001', 'prod00000001'));
    }

    public function testListsAreScopedToUserAndTenant(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);

        self::assertSame([], $repo->productUuidsForUser($this->context, '', 'user00000002'));
        self::assertSame([], $repo->productUuidsForUser($this->context, 'tenantbbbb02', 'user00000001'));
        self::assertFalse($repo->remove($this->context, '', 'user00000002', 'prod00000001'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistRepositoryTest.php`
Expected: FAIL — `Class "Glueful\Extensions\Commerce\Wishlist\WishlistRepository" not found`.

- [ ] **Step 3: Expose the canonical uuid pattern**

`UuidBatch::UUID_PATTERN` is currently `private`, and both the service (Task 3) and the DTOs
(Task 6) need it. Promote it so there is exactly one anchored pattern in the codebase rather
than copies that drift:

```php
    /** The pinned catalog-identifier shape. `\A..\z`, never `^..$`: PCRE's `$` also matches
     *  before a trailing newline, which would let "prod00000001\n" pass and then match nothing. */
    public const UUID_PATTERN = '/\A[A-Za-z0-9]{12}\z/';
```

Its uses inside `normalize()` are unchanged (`self::UUID_PATTERN` still resolves).

- [ ] **Step 4: Write the repository**

`src/Wishlist/WishlistRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Wishlist;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * Rows and the parent lock for the account wishlist. No business rules live here: the cap,
 * availability and merge ordering belong to {@see WishlistService}.
 *
 * The `ensureList()` / `claimList()` pair mirrors
 * {@see \Glueful\Extensions\Commerce\Customers\AddressBookRepository}: ensure OUTSIDE any
 * transaction (idempotent, insert-or-ignore against the unique index), claim INSIDE it
 * (affected-row-checked), and only then read-and-mutate. The claimed parent row is what
 * actually serializes two concurrent writes to one account.
 *
 * Every method takes `$tenant` explicitly — resolution is a service concern, so a repository
 * can never silently read the wrong tenant.
 */
final class WishlistRepository
{
    private const LISTS_TABLE = 'commerce_wishlists';
    private const ITEMS_TABLE = 'commerce_wishlist_items';

    /**
     * Create the parent list row if it does not exist yet.
     *
     * Nested transaction for the same reason as {@see insertAt()}: a unique violation aborts a
     * PostgreSQL transaction, so the winner-row check must run outside the failed statement's
     * scope. Only a VERIFIED duplicate is swallowed — an unrelated failure here would otherwise
     * surface later as an inexplicable "could not be saved" from the claim.
     */
    public function ensureList(ApplicationContext $context, string $tenant, string $userUuid): void
    {
        try {
            db($context)->transaction(function () use ($context, $tenant, $userUuid): void {
                db($context)->table(self::LISTS_TABLE)->insert([
                    'uuid' => Utils::generateNanoID(12),
                    'tenant_uuid' => $tenant,
                    'user_uuid' => $userUuid,
                    'revision' => 0,
                ]);
            });
        } catch (\PDOException $e) {
            // A losing concurrent ensure simply uses the winner's row.
            if (!$this->hasList($context, $tenant, $userUuid)) {
                throw $e;
            }
        }
    }

    public function hasList(ApplicationContext $context, string $tenant, string $userUuid): bool
    {
        return db($context)->table(self::LISTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->count() > 0;
    }

    /** Affected-row-checked claim: false means there is no list row to serialize on. */
    public function claimList(ApplicationContext $context, string $tenant, string $userUuid): bool
    {
        $affected = db($context)->table(self::LISTS_TABLE)->executeModification(
            <<<'SQL'
UPDATE commerce_wishlists
SET revision = revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND user_uuid = ?
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $userUuid]
        );

        return $affected === 1;
    }

    /** @return list<array<string,mixed>> display order */
    public function forUser(ApplicationContext $context, string $tenant, string $userUuid): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = db($context)->table(self::ITEMS_TABLE)
            ->select(['uuid', 'product_uuid', 'position', 'created_at'])
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->orderBy('position', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $rows;
    }

    /** @return list<string> product uuids in display order */
    public function productUuidsForUser(ApplicationContext $context, string $tenant, string $userUuid): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['product_uuid'],
            $this->forUser($context, $tenant, $userUuid)
        ));
    }

    public function countForUser(ApplicationContext $context, string $tenant, string $userUuid): int
    {
        return (int) db($context)->table(self::ITEMS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->count();
    }

    /** Lowest position in the list, or 0 when empty. Saves go to `frontPosition() - 1`. */
    public function frontPosition(ApplicationContext $context, string $tenant, string $userUuid): int
    {
        return $this->boundary($context, $tenant, $userUuid, 'MIN');
    }

    /** Highest position in the list, or 0 when empty. Imports append from `backPosition() + 1`. */
    public function backPosition(ApplicationContext $context, string $tenant, string $userUuid): int
    {
        return $this->boundary($context, $tenant, $userUuid, 'MAX');
    }

    /**
     * Insert at an explicit position.
     *
     * The insert runs in its OWN nested transaction (a savepoint when the caller already holds
     * one), because PostgreSQL aborts the whole transaction on a unique violation: the
     * duplicate re-check below would itself fail with "current transaction is aborted" if it
     * ran inside the poisoned transaction. Rolling back to the savepoint leaves the caller's
     * transaction usable. Same discipline as
     * {@see \Glueful\Extensions\Commerce\Marketplace\ChargebackRepository::insert()}.
     *
     * Returns false ONLY when the product is already on this list — verified by re-reading
     * after the failure. Any other error is rethrown: an outage or schema fault reported as
     * "already saved" would silently lose a save the caller believes succeeded.
     */
    public function insertAt(
        ApplicationContext $context,
        string $tenant,
        string $userUuid,
        string $productUuid,
        int $position,
    ): bool {
        $row = [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant,
            'user_uuid' => $userUuid,
            'product_uuid' => $productUuid,
            'position' => $position,
            'created_at' => db($context)->getDriver()->formatDateTime(),
        ];

        try {
            db($context)->transaction(function () use ($context, $row): void {
                db($context)->table(self::ITEMS_TABLE)->insert($row);
            });
        } catch (\PDOException $e) {
            // Verified duplicate -> idempotent no-op. Anything else is a real failure.
            if ($this->has($context, $tenant, $userUuid, $productUuid)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function has(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid): bool
    {
        return db($context)->table(self::ITEMS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('product_uuid', '=', $productUuid)
            ->count() > 0;
    }

    public function remove(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid): bool
    {
        $affected = db($context)->table(self::ITEMS_TABLE)->executeModification(
            <<<'SQL'
DELETE FROM commerce_wishlist_items
WHERE tenant_uuid = ? AND user_uuid = ? AND product_uuid = ?
SQL,
            [$tenant, $userUuid, $productUuid]
        );

        return $affected > 0;
    }

    private function boundary(ApplicationContext $context, string $tenant, string $userUuid, string $fn): int
    {
        /** @var array<string,mixed>|null $row */
        $row = db($context)->table(self::ITEMS_TABLE)
            ->selectRaw($fn . '(position) AS boundary')
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->first();

        $value = $row['boundary'] ?? null;

        return $value === null ? 0 : (int) $value;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistRepositoryTest.php`
Expected: PASS (9 tests). If `selectRaw()` is not this query builder's raw-select method, use its
equivalent and keep the same contract (`0` when the list is empty).

- [ ] **Step 6: Prove the savepoint against real PostgreSQL**

SQLite tolerates a failed statement inside a transaction; PostgreSQL does not. The savepoint is
therefore untested until it runs against pgsql. Add a gated sibling to `WishlistRepositoryTest`,
skipping unless the pgsql env vars this suite already uses are present, building a pgsql
`Connection` + context the way `Customers\AddressBookConcurrencyTest` does:

```php
    public function testADuplicateInsideAnOpenTransactionLeavesItUsableOnPostgres(): void
    {
        $config = $this->pgConfigOrSkip();   // same helper shape AddressBookConcurrencyTest uses
        [$context, $connection] = $this->pgContext($config);

        $repo = new WishlistRepository();
        $repo->ensureList($context, '', 'userpgdup001');
        $repo->insertAt($context, '', 'userpgdup001', 'prodpgdup001', 0);

        $stillWorks = db($context)->transaction(function () use ($repo, $context): bool {
            // Without the savepoint this call aborts the transaction, and the next statement
            // fails with SQLSTATE[25P02] instead of returning.
            self::assertFalse($repo->insertAt($context, '', 'userpgdup001', 'prodpgdup001', -1));

            return $repo->insertAt($context, '', 'userpgdup001', 'prodpgdup002', -2);
        });

        self::assertTrue($stillWorks);
    }
```

Run with PostgreSQL configured: expect PASS. Run without: expect SKIPPED.

---

## Task 3: Wishlist service — availability, cap under lock, merge ordering

**Files:**
- Create: `src/Wishlist/WishlistService.php`, `src/Wishlist/WishlistImportResult.php`
- Modify: `src/CommerceServiceProvider.php`
- Test: `tests/Integration/Wishlist/WishlistServiceTest.php`

**Interfaces:**
- Consumes: `WishlistRepository` (Task 2); `ProductRepository::findActiveBuyerAvailableByUuids(ApplicationContext $context, string $tenant, array $uuids): array`; `CurrentTenantResolver::tenantUuid(ApplicationContext $context): string`; `UuidBatch::normalize(array $values): array`.
- Produces:
  - `WishlistService::MAX_ITEMS = 100`
  - `list(ApplicationContext $context, string $userUuid): array`
  - `add(ApplicationContext $context, string $userUuid, string $productUuid): bool`
  - `remove(ApplicationContext $context, string $userUuid, string $productUuid): bool`
  - `import(ApplicationContext $context, string $userUuid, array $productUuids): WishlistImportResult`
  - `WishlistImportResult` readonly: `list<string> $imported`, `list<string> $unavailable`, `list<string> $overflow`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Validation\ValidationException;

final class WishlistServiceTest extends CommerceTestCase
{
    private function product(string $uuid): string
    {
        db($this->context)->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
            'created_at' => '2026-07-01 10:00:00',
        ]);

        return $uuid;
    }

    private function service(): WishlistService
    {
        // CommerceTestCase exposes no container property; services are constructed directly,
        // matching AddressBookConcurrencyTest. SentinelTenantResolver yields the '' tenant.
        return new WishlistService(
            new WishlistRepository(),
            new ProductRepository(),
            new SentinelTenantResolver(),
        );
    }

    public function testSavesAppearNewestFirst(): void
    {
        $this->product('prod00000001');
        $this->product('prod00000002');
        $service = $this->service();

        $service->add($this->context, 'user00000001', 'prod00000001');
        $service->add($this->context, 'user00000001', 'prod00000002');

        self::assertSame(
            ['prod00000002', 'prod00000001'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }

    public function testAnUnavailableProductCannotConsumeACapSlot(): void
    {
        // Without this check an arbitrary uuid fills the list invisibly: it never renders
        // (the read filters on availability) but it still counts against the cap.
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->add($this->context, 'user00000001', 'prodmissing1');
    }

    public function testAProductThatGoesInactiveLeavesTheListWithoutBeingDeleted(): void
    {
        $this->product('prod00000001');
        $service = $this->service();
        $service->add($this->context, 'user00000001', 'prod00000001');

        db($this->context)->table('commerce_products')->executeModification(
            "UPDATE commerce_products SET status = 'draft' WHERE uuid = ?",
            ['prod00000001']
        );

        self::assertSame([], $service->list($this->context, 'user00000001'));
        // The saved row survives, so reactivating the product brings the item back.
        self::assertSame(1, (new WishlistRepository())->countForUser($this->context, '', 'user00000001'));
    }

    public function testTheCapIsRefusedExplicitlyRatherThanEvictingSilently(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }
        $this->product('prod00000999');

        $this->expectException(ValidationException::class);
        try {
            $service->add($this->context, 'user00000001', 'prod00000999');
        } finally {
            self::assertSame(
                WishlistService::MAX_ITEMS,
                (new WishlistRepository())->countForUser($this->context, '', 'user00000001')
            );
        }
    }

    public function testReSavingAnAlreadySavedProductAtTheCapIsANoOpNotAnError(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }

        // The cap governs growth; a duplicate save adds nothing, so it is not refused.
        self::assertFalse($service->add($this->context, 'user00000001', 'prod00000001'));
    }

    public function testImportKeepsAccountOrderThenAppendsDeviceOrder(): void
    {
        foreach (['prodaccount1', 'prodaccount2', 'proddevice01', 'proddevice02'] as $uuid) {
            $this->product($uuid);
        }
        $service = $this->service();
        $service->add($this->context, 'user00000001', 'prodaccount1');
        $service->add($this->context, 'user00000001', 'prodaccount2');

        $result = $service->import($this->context, 'user00000001', [
            'proddevice01',
            'prodaccount1',
            'proddevice02',
        ]);

        self::assertSame(['proddevice01', 'proddevice02'], $result->imported);
        self::assertSame([], $result->unavailable);
        self::assertSame([], $result->overflow);

        // Account items keep their own order (newest save first); device-only items follow in
        // the order the device supplied. A device list carries UUIDs and no timestamps, so no
        // time-based interleave is claimed or reconstructed.
        self::assertSame(
            ['prodaccount2', 'prodaccount1', 'proddevice01', 'proddevice02'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }

    public function testImportDropsUnavailableProductsAndReportsThem(): void
    {
        $this->product('proddevice01');
        $service = $this->service();

        $result = $service->import($this->context, 'user00000001', ['proddevice01', 'prodmissing1']);

        self::assertSame(['proddevice01'], $result->imported);
        self::assertSame(['prodmissing1'], $result->unavailable);
    }

    public function testImportFillsRemainingHeadroomAndReportsTheOverflow(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS - 1; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }
        $this->product('proddevice01');
        $this->product('proddevice02');

        $result = $service->import($this->context, 'user00000001', ['proddevice01', 'proddevice02']);

        self::assertSame(['proddevice01'], $result->imported);
        // Preserved for the caller to keep locally rather than silently dropped.
        self::assertSame(['proddevice02'], $result->overflow);
        self::assertSame(
            WishlistService::MAX_ITEMS,
            (new WishlistRepository())->countForUser($this->context, '', 'user00000001')
        );
    }

    public function testOnlyValidUniqueExcessIdentifiersBecomeOverflow(): void
    {
        // A direct service caller (another pack, not the HTTP boundary) can hand over more than
        // the batch limit. Everything past it must be reported, but overflow means "valid, did
        // not fit" — telling a caller to keep a malformed string it can never import would be a
        // lie it acts on by preserving garbage locally forever.
        $service = $this->service();
        $limit = \Glueful\Extensions\Commerce\Support\UuidBatch::LIMIT;

        $input = [];
        for ($i = 1; $i <= $limit; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $input[] = $uuid;
        }
        // Excess, in order: two valid new products, a duplicate of one of them, a duplicate of
        // an in-limit uuid, and two malformed strings.
        $this->product('prodexcess01');
        $this->product('prodexcess02');
        $input[] = 'prodexcess01';
        $input[] = 'prodexcess02';
        $input[] = 'prodexcess01';
        $input[] = 'prod00000001';
        $input[] = 'nope';
        $input[] = "prodexcess03\n";

        $result = $service->import($this->context, 'user00000001', $input);

        // The first `limit` valid uuids import (the account starts empty, and limit == the cap).
        self::assertCount($limit, $result->imported);
        // Only the valid, unique, not-already-counted excess is overflow: no duplicates, no
        // malformed strings, and not the uuid that was already inside the batch window.
        self::assertSame(['prodexcess01', 'prodexcess02'], $result->overflow);
    }

    public function testImportIsIdempotent(): void
    {
        $this->product('proddevice01');
        $service = $this->service();

        self::assertSame(
            ['proddevice01'],
            $service->import($this->context, 'user00000001', ['proddevice01'])->imported
        );
        self::assertSame([], $service->import($this->context, 'user00000001', ['proddevice01'])->imported);
        self::assertSame(1, (new WishlistRepository())->countForUser($this->context, '', 'user00000001'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistServiceTest.php`
Expected: FAIL — `Class "Glueful\Extensions\Commerce\Wishlist\WishlistService" not found`.

- [ ] **Step 3: Write the import result**

`src/Wishlist/WishlistImportResult.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Wishlist;

/**
 * What an import actually did.
 *
 * The caller holds a device-local list it may want to clear afterwards, so it must be told
 * exactly which uuids landed. `overflow` exists so a list that did not fit is preserved by the
 * caller instead of vanishing: the account is capped, but the visitor's saved items are not
 * the framework's to discard.
 */
final class WishlistImportResult
{
    /**
     * @param list<string> $imported    newly added to the account
     * @param list<string> $unavailable dropped: unknown or not buyer-available
     * @param list<string> $overflow    valid, but the account was at its cap
     */
    public function __construct(
        public readonly array $imported,
        public readonly array $unavailable,
        public readonly array $overflow,
    ) {
    }
}
```

- [ ] **Step 4: Write the service**

`src/Wishlist/WishlistService.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Wishlist;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\ValidationException;

/**
 * Account-backed wishlist rules (accounts design spec §11).
 *
 * Every growth path takes the same shape: `ensureList()` outside the transaction, `claimList()`
 * inside it, then re-read the count and mutate. Without that parent claim a count-then-insert
 * is a race — two concurrent saves both read 99 and both insert, and the cap silently becomes
 * a suggestion.
 *
 * Availability is checked BEFORE a product can consume a slot, and re-checked on every read
 * through {@see ProductRepository::findActiveBuyerAvailableByUuids()}. A product that goes
 * inactive therefore leaves the list WITHOUT deleting the saved row: reactivate it and the
 * item returns.
 *
 * The cap is refused, never evicted. Silently dropping the oldest item to make room would
 * discard something a visitor deliberately saved.
 */
final class WishlistService
{
    /** Per (tenant, user). Matches the device-local bound so a full local list can round-trip. */
    public const MAX_ITEMS = 100;

    public function __construct(
        private WishlistRepository $repository,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * The user's saved products that are currently buyer-available, in display order.
     *
     * @return list<array<string,mixed>>
     */
    public function list(ApplicationContext $context, string $userUuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $saved = $this->repository->productUuidsForUser($context, $tenant, $userUuid);
        if ($saved === []) {
            return [];
        }

        $available = $this->availableByUuid($context, $tenant, $saved);

        $out = [];
        foreach ($saved as $uuid) {
            if (isset($available[$uuid])) {
                $out[] = $available[$uuid];
            }
        }

        return $out;
    }

    /**
     * Save a product to the front of the list.
     *
     * @throws ValidationException When the product is unavailable, or the list is full.
     */
    public function add(ApplicationContext $context, string $userUuid, string $productUuid): bool
    {
        $tenant = $this->tenants->tenantUuid($context);

        // Availability first: an unavailable uuid must never occupy a slot, since the read
        // filters it out and the visitor would see a shorter list that still refuses saves.
        if (!isset($this->availableByUuid($context, $tenant, [$productUuid])[$productUuid])) {
            throw ValidationException::forField('product_uuid', 'That product is not available.');
        }

        $this->repository->ensureList($context, $tenant, $userUuid);

        return db($context)->transaction(function () use ($context, $tenant, $userUuid, $productUuid): bool {
            if (!$this->repository->claimList($context, $tenant, $userUuid)) {
                throw ValidationException::forField('product_uuid', 'That product could not be saved.');
            }

            // Re-read UNDER the claim: a concurrent save may have added this product or taken
            // the last slot since the caller's own view of the list.
            if ($this->repository->has($context, $tenant, $userUuid, $productUuid)) {
                return false;
            }
            if ($this->repository->countForUser($context, $tenant, $userUuid) >= self::MAX_ITEMS) {
                throw ValidationException::forField(
                    'product_uuid',
                    sprintf('Your wishlist is full (%d items). Remove something to save this one.', self::MAX_ITEMS)
                );
            }

            $position = $this->repository->frontPosition($context, $tenant, $userUuid) - 1;

            return $this->repository->insertAt($context, $tenant, $userUuid, $productUuid, $position);
        });
    }

    public function remove(ApplicationContext $context, string $userUuid, string $productUuid): bool
    {
        return $this->repository->remove($context, $this->tenants->tenantUuid($context), $userUuid, $productUuid);
    }

    /**
     * Merge a device-local list into the account.
     *
     * Existing account items keep their own order; device-only items are appended in the order
     * the device supplied them. A device list carries UUIDs and no timestamps, so interleaving
     * by time is not something this merge could reconstruct, and it does not pretend to.
     *
     * @param list<string> $productUuids device order, first = the device's newest
     */
    public function import(ApplicationContext $context, string $userUuid, array $productUuids): WishlistImportResult
    {
        $tenant = $this->tenants->tenantUuid($context);
        $candidates = UuidBatch::normalize($productUuids);

        // normalize() keeps the FIRST UuidBatch::LIMIT values and drops the rest. Dropping them
        // silently would report them as neither imported nor overflow, so a caller clearing its
        // local list would lose them. Anything valid beyond the batch limit is overflow: the
        // visitor-facing action is identical — keep it locally and import it later.
        $beyondLimit = [];
        if (count($productUuids) > UuidBatch::LIMIT) {
            $kept = array_flip($candidates);
            $seen = [];
            foreach ($productUuids as $uuid) {
                // Same shape test normalize() applies. Reporting a malformed string as overflow
                // would tell the caller to keep something that can never import — the contract
                // says overflow is VALID but did not fit.
                if (!is_string($uuid) || preg_match(UuidBatch::UUID_PATTERN, $uuid) !== 1) {
                    continue;
                }
                if (isset($kept[$uuid]) || isset($seen[$uuid])) {
                    continue;
                }
                $seen[$uuid] = true;
                $beyondLimit[] = $uuid;
            }
        }

        if ($candidates === []) {
            return new WishlistImportResult([], [], $beyondLimit);
        }

        $available = $this->availableByUuid($context, $tenant, $candidates);
        $this->repository->ensureList($context, $tenant, $userUuid);

        return db($context)->transaction(
            function () use (
                $context,
                $tenant,
                $userUuid,
                $candidates,
                $available,
                $beyondLimit
            ): WishlistImportResult {
                if (!$this->repository->claimList($context, $tenant, $userUuid)) {
                    // Nothing was imported and nothing was lost: the caller keeps its local list.
                    return new WishlistImportResult([], [], array_merge($candidates, $beyondLimit));
                }

                $saved = $this->repository->productUuidsForUser($context, $tenant, $userUuid);
                $count = count($saved);
                $position = $this->repository->backPosition($context, $tenant, $userUuid);

                $imported = [];
                $unavailable = [];
                $overflow = [];

                foreach ($candidates as $uuid) {
                    if (in_array($uuid, $saved, true)) {
                        continue; // dedupe by product uuid — the account copy wins
                    }
                    if (!isset($available[$uuid])) {
                        $unavailable[] = $uuid;
                        continue;
                    }
                    if ($count >= self::MAX_ITEMS) {
                        $overflow[] = $uuid;
                        continue;
                    }
                    if ($this->repository->insertAt($context, $tenant, $userUuid, $uuid, ++$position)) {
                        $imported[] = $uuid;
                        $count++;
                    }
                }

                return new WishlistImportResult($imported, $unavailable, array_merge($overflow, $beyondLimit));
            }
        );
    }

    /**
     * @param list<string> $uuids
     * @return array<string,array<string,mixed>> keyed by product uuid
     */
    private function availableByUuid(ApplicationContext $context, string $tenant, array $uuids): array
    {
        $byUuid = [];
        foreach ($this->products->findActiveBuyerAvailableByUuids($context, $tenant, $uuids) as $row) {
            $byUuid[(string) $row['uuid']] = $row;
        }

        return $byUuid;
    }
}
```

- [ ] **Step 5: Register the service**

In `src/CommerceServiceProvider.php`, add to `services()` beside `AddressBookService::class`:

```php
            WishlistService::class => [
                'factory' => [self::class, 'makeWishlistService'],
                'shared' => true,
            ],
```

and the factory beside `makeAddressBookService()`:

```php
    public static function makeWishlistService(ContainerInterface $container): WishlistService
    {
        return new WishlistService(
            new WishlistRepository(),
            $container->get(ProductRepository::class),
            $container->get(CurrentTenantResolver::class),
        );
    }
```

Import `WishlistRepository` and `WishlistService` at the top and use the short names (never
inline FQCNs in `services()` or factories).

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistServiceTest.php`
Expected: PASS (10 tests).

---

## Task 4: Concurrency proofs

**Files:**
- Create: `tests/Integration/Wishlist/WishlistConcurrencyTest.php`, `tests/Integration/Wishlist/fixtures/wishlist_race_child.php`

**Interfaces:**
- Consumes: `WishlistRepository`, `WishlistService` (Tasks 2–3).
- Produces: nothing.

**Why:** the cap and the ordering are the two claims this design makes that a single-threaded test cannot establish. Follow the split this suite already uses (`Customers\AddressBookConcurrencyTest`): a deterministic sequential proof that runs everywhere, plus pgsql-gated tests that hold the parent claim in one connection while a child process attempts the same growth path.

- [ ] **Step 1: Write the deterministic proof**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Validation\ValidationException;

/**
 * The cap and the merge order are claims about a SET, so they hold only if every growth path
 * serializes on the list's parent row. A deterministic sibling proves the outcome sequentially
 * here; the pgsql-gated tests prove it under a real row-lock interleave.
 */
final class WishlistConcurrencyTest extends CommerceTestCase
{
    private function product(string $uuid): string
    {
        db($this->context)->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
            'created_at' => '2026-07-01 10:00:00',
        ]);

        return $uuid;
    }

    private function service(): WishlistService
    {
        return new WishlistService(new WishlistRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    public function testTheLastSlotIsWonByExactlyOneOfTwoSequentialSaves(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS - 1; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }
        $this->product('prodracea001');
        $this->product('prodraceb002');

        self::assertTrue($service->add($this->context, 'user00000001', 'prodracea001'));

        // The loser is refused, not silently squeezed in past the cap.
        try {
            $service->add($this->context, 'user00000001', 'prodraceb002');
            self::fail('The second save should have been refused at the cap.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(
            WishlistService::MAX_ITEMS,
            (new WishlistRepository())->countForUser($this->context, '', 'user00000001')
        );
    }

    public function testAnImportRunAfterASaveStillAppendsBehindIt(): void
    {
        foreach (['prodaccount1', 'proddevice01'] as $uuid) {
            $this->product($uuid);
        }
        $service = $this->service();

        $service->add($this->context, 'user00000001', 'prodaccount1');
        $service->import($this->context, 'user00000001', ['proddevice01']);

        self::assertSame(
            ['prodaccount1', 'proddevice01'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Integration/Wishlist/WishlistConcurrencyTest.php`
Expected: PASS (2 tests).

- [ ] **Step 3: Write the child-process fixture**

Read `tests/Integration/Customers/fixtures/address_default_race_child.php` first and mirror its
bootstrap exactly — it is the working reference for building a `Connection` + `ApplicationContext`
+ container in a subprocess. The wishlist child differs only in what it runs:

`tests/Integration/Wishlist/fixtures/wishlist_race_child.php`

```php
<?php

declare(strict_types=1);

// Bootstrap identical to address_default_race_child.php (autoload, Connection from the JSON
// pgsql config, ApplicationContext::forTesting, and the same container shape CommerceTestCase
// builds — the child must resolve exactly what the service resolves in-process).

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;

[, $configJson, $tenant, $userUuid, $operation, $payload] = $argv;

$result = ['exceptionClass' => null, 'message' => null, 'imported' => [], 'count' => 0];

try {
    // ... bootstrap $context exactly as the address fixture does ...

    $service = new WishlistService(new WishlistRepository(), new ProductRepository(), new SentinelTenantResolver());

    if ($operation === 'add') {
        $service->add($context, $userUuid, $payload);
    } else {
        $result['imported'] = $service->import($context, $userUuid, explode(',', $payload))->imported;
    }

    $result['count'] = (new WishlistRepository())->countForUser($context, $tenant, $userUuid);
} catch (\Throwable $e) {
    $result['exceptionClass'] = $e::class;
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
```

- [ ] **Step 4: Write the pgsql-gated races**

Add to `WishlistConcurrencyTest`, following `AddressBookConcurrencyTest`'s structure: skip unless
the pgsql env vars are present; self-heal debris from an interrupted run; open a transaction on
connection A and take `claimList()` directly (the primitive, not the service, so the test can hold
the lock mid-path); `proc_open` the child for connection B; `usleep(300_000)` so B blocks on its
own claim; complete A's insert; commit; then read B's JSON result.

Three races, each asserting the invariant the lock exists to protect:

1. **add vs add at the last slot** — both target the final slot. Exactly one succeeds; the other
   reports a `ValidationException`; the final count is exactly `MAX_ITEMS`.
2. **add vs import** — a save and a merge grow the list together. The final count never exceeds
   `MAX_ITEMS`, and every imported item's `position` is greater than every pre-existing item's.
3. **import vs import** — the same device list imported twice concurrently yields each product
   exactly once, reported as imported by exactly one of the two runs.

- [ ] **Step 5: Run the suite both ways**

Run: `vendor/bin/phpunit tests/Integration/Wishlist`
Expected: PASS, with the pgsql tests skipped when no PostgreSQL is configured.

With PostgreSQL available (same env vars `AddressBookConcurrencyTest` uses):
Expected: PASS with the three races executing.

- [ ] **Step 6: Full gates, then commit**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 src tests
git add migrations/020_CreateWishlistTables.php src/Wishlist src/Support/DiagnosticsReport.php src/CommerceServiceProvider.php tests/Support/CommerceTestCase.php tests/Integration/Wishlist
git commit -m "feat(wishlist): add the account-backed wishlist with a serialized cap and explicit order

A parent list row per (tenant, user) carries the revision claim every growth path
takes: ensure outside the transaction, claim inside it, then re-read and mutate.
Without that lock the cap is a suggestion — two concurrent saves both read 99 and
both insert.

Order is an explicit position, not a timestamp. Saves take the front (min - 1),
imports append to the back, so a merge keeps the account's own items ahead of
device-only ones; sorting by created_at would have put freshly imported rows first,
inverting the rule. Availability is checked before a product can consume a slot and
re-checked on every read, so an inactive product leaves the list without deleting the
saved row. The 100-item cap is refused explicitly rather than evicting a deliberately
saved item, and import overflow is reported back rather than dropped.

Both tables join the tenancy inventory, so tenant registration, adoption, diagnostics
and purge carry them."
```

---

## Task 5: Guest-order claim service (no HTTP)

**Files:**
- Create: `src/Orders/GuestOrderClaimService.php`
- Modify: `src/CommerceServiceProvider.php`
- Test: `tests/Integration/Orders/GuestOrderClaimTest.php`

**Interfaces:**
- Consumes: `OrderRepository::findByNumber(ApplicationContext $context, string $tenant, string $number): ?array` (exists at `src/Orders/OrderRepository.php:56`); `OrderRepository::linkGuestToUser(...)`; `OrderRepository::paginatedFor(...)` with the `email_normalized` filter (already scoped to `user_uuid IS NULL`, returning full rows under `items`); `TokenHasher::hash()`; `EmailNormalizer::normalize()`; `CurrentTenantResolver`.
- Produces:
  - `claim(ApplicationContext $context, string $userUuid, string $verifiedEmail, string $orderNumber, string $guestToken): array`
  - `claimAllByVerifiedEmail(ApplicationContext $context, string $userUuid, string $verifiedEmail): list<string>`
  - `GuestOrderClaimService::HISTORICAL_IMPORT_LIMIT = 100`

**No controller, no routes.** Commerce cannot establish that an email is verified: the `'user'`
request attribute carries no email under JWT auth, and `glueful/users` drops `email_verified_at`
when building `UserIdentity`. A Commerce endpoint would therefore trust a client-asserted address
or fail closed forever. The calling application owns that context and invokes these methods
server-side — and the historical variant, whose only proof is the email, is the host's to gate
behind fresh authentication, explicit confirmation and an audit record. A docblock cannot enforce
that; an absent route can.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Orders\GuestOrderClaimService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;

final class GuestOrderClaimTest extends CommerceTestCase
{
    private const TOKEN = 'guest-token-abc';

    private function order(
        string $uuid,
        string $number,
        string $email = 'buyer@example.test',
        ?string $userUuid = null,
        string $tenant = '',
    ): void {
        db($this->context)->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => $number,
            'email' => $email,
            'user_uuid' => $userUuid,
            'guest_token_hash' => TokenHasher::hash(self::TOKEN),
            'status' => 'paid',
            'currency' => 'USD',
            'grand_total' => 1000,
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    private function service(): GuestOrderClaimService
    {
        return new GuestOrderClaimService(new OrderRepository(), new SentinelTenantResolver());
    }

    public function testBothProofsTogetherClaimTheOrder(): void
    {
        $this->order('ordr00000001', 'ORD-1');

        $claimed = $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-1', self::TOKEN);

        self::assertSame('ordr00000001', $claimed['uuid']);
        self::assertSame('user00000001', $claimed['user_uuid']);
        self::assertArrayNotHasKey('guest_token_hash', $claimed);
    }

    public function testEmailIsMatchedAfterNormalization(): void
    {
        $this->order('ordr00000002', 'ORD-2', ' Buyer@Example.test ');

        $claimed = $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-2', self::TOKEN);

        self::assertSame('user00000001', $claimed['user_uuid']);
    }

    public function testAWrongGuestTokenIsRejectedEvenWhenTheEmailMatches(): void
    {
        // Email verification proves mailbox control, not that this person placed the order.
        $this->order('ordr00000003', 'ORD-3');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-3', 'wrong-token');
    }

    public function testAMismatchedEmailIsRejectedEvenWithAValidToken(): void
    {
        // The token may have been forwarded in a receipt.
        $this->order('ordr00000004', 'ORD-4');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'someone@example.test', 'ORD-4', self::TOKEN);
    }

    public function testAnOrderOwnedByAnotherUserIsNotRevealed(): void
    {
        $this->order('ordr00000005', 'ORD-5', 'buyer@example.test', 'user00000002');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-5', self::TOKEN);
    }

    public function testReClaimingYourOwnOrderIsASuccessfulNoOp(): void
    {
        // A double-submitted form must not 404 a visitor out of their own order.
        $this->order('ordr00000006', 'ORD-6', 'buyer@example.test', 'user00000001');

        $claimed = $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-6', self::TOKEN);

        self::assertSame('user00000001', $claimed['user_uuid']);
    }

    public function testAnOrderInAnotherTenantIsNotClaimable(): void
    {
        $this->order('ordr00000007', 'ORD-7', 'buyer@example.test', null, 'tenantaaaa01');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-7', self::TOKEN);
    }

    public function testEveryFailureCarriesTheIdenticalMessage(): void
    {
        // Distinguishable messages would turn this into an order-existence oracle.
        $this->order('ordr00000008', 'ORD-8', 'buyer@example.test', 'user00000002');
        $messages = [];

        foreach ([
            ['ORD-NOPE', self::TOKEN, 'buyer@example.test'],
            ['ORD-8', 'wrong-token', 'buyer@example.test'],
            ['ORD-8', self::TOKEN, 'other@example.test'],
            ['ORD-8', self::TOKEN, 'buyer@example.test'],
        ] as [$number, $token, $email]) {
            try {
                $this->service()->claim($this->context, 'user00000001', $email, $number, $token);
                self::fail('Expected the claim to fail for ' . $number);
            } catch (NotFoundException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame(['Resource not found.'], array_values(array_unique($messages)));
    }

    public function testHistoricalImportClaimsEveryUnownedOrderMatchingTheVerifiedEmail(): void
    {
        $this->order('ordr00000010', 'ORD-10');
        $this->order('ordr00000011', 'ORD-11', 'BUYER@Example.test ');
        $this->order('ordr00000012', 'ORD-12', 'someone@example.test');
        $this->order('ordr00000013', 'ORD-13', 'buyer@example.test', 'user00000002');

        $claimed = $this->service()->claimAllByVerifiedEmail($this->context, 'user00000001', 'buyer@example.test');

        sort($claimed);
        self::assertSame(['ORD-10', 'ORD-11'], $claimed);
    }

    public function testHistoricalImportIsIdempotent(): void
    {
        $this->order('ordr00000014', 'ORD-14');
        $service = $this->service();

        self::assertSame(
            ['ORD-14'],
            $service->claimAllByVerifiedEmail($this->context, 'user00000001', 'buyer@example.test')
        );
        self::assertSame([], $service->claimAllByVerifiedEmail($this->context, 'user00000001', 'buyer@example.test'));
    }

    public function testHistoricalImportRefusesAnEmptyEmail(): void
    {
        // A blank "verified" email must never match guest orders whose email is also blank.
        $this->order('ordr00000015', 'ORD-15', '');

        self::assertSame([], $this->service()->claimAllByVerifiedEmail($this->context, 'user00000001', ''));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Orders/GuestOrderClaimTest.php`
Expected: FAIL — `Class "Glueful\Extensions\Commerce\Orders\GuestOrderClaimService" not found`.

- [ ] **Step 3: Write the service**

`src/Orders/GuestOrderClaimService.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Customer-safe guest-order claiming (accounts design spec §10).
 *
 * {@see OrderRepository::linkGuestToUser()} is race-safe but unguarded — it stamps any unowned
 * order. That is right for the operator CLI and wrong for anything a visitor can drive, so this
 * service supplies the proofs a customer-initiated claim needs.
 *
 * A SERVICE, deliberately not an endpoint. Commerce cannot establish that an email is verified:
 * the post-auth `user` attribute carries no email under JWT authentication, and the users
 * extension drops `email_verified_at` when building its identity. The calling application owns
 * that context and passes an email it has actually verified.
 *
 * `claim()` requires BOTH proofs: the guest credential from checkout AND a normalized email
 * match. Email alone is not ownership — verification proves current mailbox control, and
 * addresses get recycled, shared and mistyped. The token alone is not enough either: receipts
 * get forwarded.
 *
 * Every failure raises the SAME non-revealing 404, so this cannot be used to probe which order
 * numbers exist. Re-claiming an order you already own succeeds as a no-op.
 */
final class GuestOrderClaimService
{
    /** Orders claimed per historical-import call. Run it again to continue. */
    public const HISTORICAL_IMPORT_LIMIT = 100;

    public function __construct(
        private OrderRepository $orders,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @return array<string,mixed> the claimed order row (guest_token_hash stripped)
     * @throws NotFoundException On any failure, always with the same message.
     */
    public function claim(
        ApplicationContext $context,
        string $userUuid,
        string $verifiedEmail,
        string $orderNumber,
        string $guestToken,
    ): array {
        $tenant = $this->tenants->tenantUuid($context);
        $order = $this->orders->findByNumber($context, $tenant, $orderNumber);
        if ($order === null || $userUuid === '') {
            throw $this->notFound();
        }

        $storedHash = (string) ($order['guest_token_hash'] ?? '');
        if ($guestToken === '' || $storedHash === '' || !hash_equals($storedHash, TokenHasher::hash($guestToken))) {
            throw $this->notFound();
        }

        $orderEmail = EmailNormalizer::normalize((string) ($order['email'] ?? ''));
        if ($orderEmail === '' || $orderEmail !== EmailNormalizer::normalize($verifiedEmail)) {
            throw $this->notFound();
        }

        $owner = $order['user_uuid'] ?? null;
        if (is_string($owner) && $owner !== '') {
            // Already owned: by this caller it is a no-op success, by anyone else it is
            // indistinguishable from an order that does not exist.
            if ($owner !== $userUuid) {
                throw $this->notFound();
            }

            return $this->projection($order);
        }

        // Race-safe: stamps only while user_uuid IS NULL. Losing the race means somebody else
        // got there first, which is exactly the "owned by another user" case.
        if (!$this->orders->linkGuestToUser($context, $tenant, (string) $order['uuid'], $userUuid)) {
            $current = $this->orders->findByNumber($context, $tenant, $orderNumber);
            if ($current !== null && ($current['user_uuid'] ?? null) === $userUuid) {
                return $this->projection($current);
            }

            throw $this->notFound();
        }

        $order['user_uuid'] = $userUuid;

        return $this->projection($order);
    }

    /**
     * Explicit historical import: claim every unowned order in this tenant whose normalized
     * email matches the caller's VERIFIED email.
     *
     * Deliberately weaker than {@see claim()} — there is no guest credential, because a visitor
     * claiming months-old orders no longer holds one. That is exactly why this must be an
     * explicit, confirmed, audited action in the calling application and never an automatic
     * login side effect: a recycled or mistyped address would otherwise hand over a stranger's
     * shipping addresses, downloads and purchase history.
     *
     * @return list<string> order numbers actually claimed by this call
     */
    public function claimAllByVerifiedEmail(
        ApplicationContext $context,
        string $userUuid,
        string $verifiedEmail,
    ): array {
        $normalized = EmailNormalizer::normalize($verifiedEmail);
        if ($normalized === '' || $userUuid === '') {
            return [];
        }

        $tenant = $this->tenants->tenantUuid($context);
        // The `email_normalized` filter is already scoped to `user_uuid IS NULL`, so this can
        // never return an order that belongs to somebody else.
        $result = $this->orders->paginatedFor(
            $context,
            $tenant,
            ['email_normalized' => $normalized],
            1,
            self::HISTORICAL_IMPORT_LIMIT,
        );

        $claimed = [];
        foreach ($result['items'] as $order) {
            if ($this->orders->linkGuestToUser($context, $tenant, (string) $order['uuid'], $userUuid)) {
                $claimed[] = (string) $order['order_number'];
            }
        }

        return $claimed;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function projection(array $order): array
    {
        unset($order['guest_token_hash']);

        return $order;
    }

    private function notFound(): NotFoundException
    {
        return new NotFoundException('Resource not found.');
    }
}
```

- [ ] **Step 4: Register the service**

In `src/CommerceServiceProvider.php`:

```php
            GuestOrderClaimService::class => [
                'factory' => [self::class, 'makeGuestOrderClaimService'],
                'shared' => true,
            ],
```

```php
    public static function makeGuestOrderClaimService(ContainerInterface $container): GuestOrderClaimService
    {
        return new GuestOrderClaimService(
            $container->get(OrderRepository::class),
            $container->get(CurrentTenantResolver::class),
        );
    }
```

with `use Glueful\Extensions\Commerce\Orders\GuestOrderClaimService;` imported.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Orders/GuestOrderClaimTest.php`
Expected: PASS (11 tests).

---

## Task 6: Wishlist HTTP surface and wiring

**Files:**
- Create: `src/Http/Storefront/AccountWishlistController.php`, `src/Http/DTOs/WishlistItemData.php`, `src/Http/DTOs/WishlistImportData.php`
- Modify: `routes.php`, `src/CommerceServiceProvider.php`, `tests/Integration/ServiceProviderWiringTest.php`
- Test: `tests/Integration/Http/AccountWishlistHttpTest.php`

**Interfaces:**
- Consumes: `WishlistService` (Task 3), `GuestOrderClaimService` (Task 5, registration check only).
- Produces: `GET/POST /commerce/account/wishlist`, `POST /commerce/account/wishlist/import`, `DELETE /commerce/account/wishlist/{productUuid}` — inside the existing `auth` + tenant group. **No claim routes.**

**Identifier validation:** catalog uuids are exactly 12 alphanumeric characters (`Utils::generateNanoID(12)`). `max:12` would accept `"x"` or a traversal-shaped string, so the DTOs pin the exact shape through `ValidatesSelf`, surfacing a dot-path 422 instead of reaching the service.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistImportData;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistItemData;
use Glueful\Extensions\Commerce\Http\Storefront\AccountWishlistController;
use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class AccountWishlistHttpTest extends CommerceTestCase
{
    private function product(string $uuid): void
    {
        db($this->context)->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    private function service(): WishlistService
    {
        return new WishlistService(new WishlistRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    private function controller(): AccountWishlistController
    {
        return new AccountWishlistController($this->context, $this->service());
    }

    private function authenticated(string $userUuid = 'user00000001'): Request
    {
        $request = Request::create('/commerce/account/wishlist', 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid]);

        return $request;
    }

    public function testIndexReturnsTheUsersAvailableProducts(): void
    {
        $this->product('prod00000001');
        $this->service()->add($this->context, 'user00000001', 'prod00000001');

        $response = $this->controller()->index($this->authenticated());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('prod00000001', $body['data'][0]['uuid']);
    }

    public function testAnUnauthenticatedRequestFailsClosed(): void
    {
        // Routes are auth-gated, but a controller must never treat "no actor" as a valid user.
        $this->expectException(NotFoundException::class);
        $this->controller()->index(Request::create('/commerce/account/wishlist', 'GET'));
    }

    public function testOneUsersListIsNeverVisibleToAnother(): void
    {
        $this->product('prod00000001');
        $this->service()->add($this->context, 'user00000001', 'prod00000001');

        $response = $this->controller()->index($this->authenticated('user00000002'));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame([], $body['data']);
    }

    public function testImportReportsImportedUnavailableAndOverflowSeparately(): void
    {
        $this->product('prod00000001');

        $response = $this->controller()->import(
            new WishlistImportData(product_uuids: ['prod00000001', 'prodmissing1']),
            $this->authenticated(),
        );
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(['prod00000001'], $body['data']['imported']);
        self::assertSame(['prodmissing1'], $body['data']['unavailable']);
        self::assertSame([], $body['data']['overflow']);
    }

    public function testMalformedIdentifiersAreRejectedByTheDto(): void
    {
        // A 12-character maximum is not the same as an exact 12-character catalog uuid.
        self::assertNotSame([], (new WishlistItemData(product_uuid: 'x'))->validate());
        self::assertNotSame([], (new WishlistItemData(product_uuid: '../../etc/x'))->validate());
        // PCRE '$' matches before a trailing newline; the canonical \A..\z pattern does not.
        self::assertNotSame([], (new WishlistItemData(product_uuid: "prod00000001\n"))->validate());
        self::assertSame([], (new WishlistItemData(product_uuid: 'prod00000001'))->validate());
        self::assertNotSame([], (new WishlistImportData(product_uuids: ['prod00000001', 'nope']))->validate());
        // Over-limit is refused rather than truncated, so nothing vanishes unreported.
        self::assertNotSame(
            [],
            (new WishlistImportData(product_uuids: array_fill(0, UuidBatch::LIMIT + 1, 'prod00000001')))->validate()
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/AccountWishlistHttpTest.php`
Expected: FAIL — `Class "...Http\DTOs\WishlistItemData" not found`.

- [ ] **Step 3: Write the DTOs**

`src/Http/DTOs/WishlistItemData.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * Catalog identifiers are exactly 12 alphanumeric characters. `max:12` would accept "x" or a
 * traversal-shaped string, so the exact shape is asserted here and surfaces as a 422 instead of
 * reaching the service as a uuid that can never match anything.
 *
 * The pattern is {@see UuidBatch}'s, not a local copy: `\A...\z` anchors, because PCRE's `$`
 * also matches immediately before a trailing newline — so `"prod00000001\n"` would satisfy
 * `/^[A-Za-z0-9]{12}$/` and then fail every lookup. That bug was already fixed once in
 * UuidBatch; reusing the constant is what stops it being reintroduced here.
 */
final class WishlistItemData implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $product_uuid,
    ) {
    }

    /** @return array<string,string> */
    public function validate(): array
    {
        return preg_match(UuidBatch::UUID_PATTERN, $this->product_uuid) === 1
            ? []
            : ['product_uuid' => 'Must be a 12-character product identifier.'];
    }
}
```

`src/Http/DTOs/WishlistImportData.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

final class WishlistImportData implements RequestData, ValidatesSelf
{
    /** @param list<string> $product_uuids device order — first entry is the device's newest */
    public function __construct(
        #[Rule('required|array')]
        #[ArrayOf('string')]
        public readonly array $product_uuids,
    ) {
    }

    /** @return array<string,string> */
    public function validate(): array
    {
        // Over-limit lists are REFUSED, not truncated. `UuidBatch::normalize()` keeps the first
        // 100 and drops the rest silently — appropriate for a defensive repository read, wrong
        // here: a dropped uuid would be reported as neither imported nor overflow, so the caller
        // would clear it locally believing it had landed.
        if (count($this->product_uuids) > UuidBatch::LIMIT) {
            return ['product_uuids' => sprintf('Send at most %d products per import.', UuidBatch::LIMIT)];
        }

        foreach ($this->product_uuids as $index => $uuid) {
            if (!is_string($uuid) || preg_match(UuidBatch::UUID_PATTERN, $uuid) !== 1) {
                return ['product_uuids.' . $index => 'Must be a 12-character product identifier.'];
            }
        }

        return [];
    }
}
```

- [ ] **Step 4: Write the controller**

`src/Http/Storefront/AccountWishlistController.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistImportData;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistItemData;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The authenticated user's wishlist. Actor resolution matches
 * {@see AccountAddressController}: the post-auth `user` attribute, never `auth.user` (that
 * identity is admin-audit attribution). Routes are auth-gated, but the actor check fails
 * non-revealing anyway so a directly-constructed controller cannot act as an empty user.
 */
final class AccountWishlistController
{
    public function __construct(
        private ApplicationContext $context,
        private ?WishlistService $wishlist = null,
    ) {
        $this->wishlist ??= app($context, WishlistService::class);
    }

    #[ApiOperation(summary: "List the authenticated user's wishlist", tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Wishlist retrieved')]
    public function index(Request $request): Response
    {
        return Response::success(
            $this->wishlist->list($this->context, $this->actorUuid($request)),
            'Wishlist retrieved'
        );
    }

    #[ApiOperation(summary: 'Save a product to the wishlist', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: WishlistItemData::class)]
    #[ApiResponse(200, description: 'Saved')]
    #[ApiResponse(422, description: 'Unavailable product, or the wishlist is full')]
    public function store(WishlistItemData $input, Request $request): Response
    {
        $added = $this->wishlist->add($this->context, $this->actorUuid($request), $input->product_uuid);

        return Response::success(
            ['product_uuid' => $input->product_uuid, 'added' => $added],
            $added ? 'Saved to wishlist' : 'Already on the wishlist'
        );
    }

    #[ApiOperation(summary: 'Remove a product from the wishlist', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Removed')]
    public function destroy(Request $request, string $productUuid): Response
    {
        $removed = $this->wishlist->remove($this->context, $this->actorUuid($request), $productUuid);

        return Response::success(
            ['product_uuid' => $productUuid, 'removed' => $removed],
            $removed ? 'Removed from wishlist' : 'Not on the wishlist'
        );
    }

    #[ApiOperation(summary: 'Merge a device-local wishlist into the account', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: WishlistImportData::class)]
    #[ApiResponse(200, description: 'Import result')]
    public function import(WishlistImportData $input, Request $request): Response
    {
        $result = $this->wishlist->import($this->context, $this->actorUuid($request), $input->product_uuids);

        // The caller holds the device list and decides what to clear, so it is told exactly
        // what landed, what was dropped as unavailable, and what did not fit.
        return Response::success([
            'imported' => $result->imported,
            'unavailable' => $result->unavailable,
            'overflow' => $result->overflow,
        ], 'Wishlist imported');
    }

    private function actorUuid(Request $request): string
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';
        if ($userUuid === '') {
            throw new NotFoundException('Resource not found.');
        }

        return $userUuid;
    }
}
```

- [ ] **Step 5: Register the controller and add the routes**

In `src/CommerceServiceProvider.php`, beside `AccountAddressController::class`:

```php
            AccountWishlistController::class => [
                'factory' => [self::class, 'makeAccountWishlistController'],
                'shared' => true,
            ],
```

```php
    public static function makeAccountWishlistController(ContainerInterface $container): AccountWishlistController
    {
        return new AccountWishlistController(
            $container->get(ApplicationContext::class),
            $container->get(WishlistService::class),
        );
    }
```

In `routes.php`, inside the existing `/commerce/account` group (already carrying
`array_merge(['auth'], $tenantMiddleware)`), after the address routes:

```php
    $router->get('/wishlist', [AccountWishlistController::class, 'index']);
    $router->post('/wishlist', [AccountWishlistController::class, 'store']);
    $router->post('/wishlist/import', [AccountWishlistController::class, 'import']);
    $router->delete('/wishlist/{productUuid}', [AccountWishlistController::class, 'destroy']);
```

with `AccountWishlistController` imported. **Add no claim routes** — see Task 5.

- [ ] **Step 6: Extend the wiring test**

Add to `tests/Integration/ServiceProviderWiringTest.php`:

```php
    public function testAccountSeamServicesAndControllersResolve(): void
    {
        foreach ([
            \Glueful\Extensions\Commerce\Wishlist\WishlistService::class,
            \Glueful\Extensions\Commerce\Orders\GuestOrderClaimService::class,
            \Glueful\Extensions\Commerce\Http\Storefront\AccountWishlistController::class,
        ] as $id) {
            self::assertInstanceOf($id, app($this->context, $id), $id . ' is not registered');
        }
    }
```

- [ ] **Step 7: Confirm no claim route was registered**

Run: `grep -n "claim" routes.php`
Expected: no matches. A route would be directly callable by any authenticated client and would
bypass the fresh-auth, confirmation and audit the historical variant requires.

- [ ] **Step 8: Full gates, then commit**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 src tests
git add src/Orders/GuestOrderClaimService.php src/Http/Storefront/AccountWishlistController.php src/Http/DTOs routes.php src/CommerceServiceProvider.php tests/Integration/Orders/GuestOrderClaimTest.php tests/Integration/Http/AccountWishlistHttpTest.php tests/Integration/ServiceProviderWiringTest.php
git commit -m "feat(orders): add a customer-safe guest-order claim service and wishlist routes

GuestOrderClaimService supplies the proofs a visitor-driven claim needs on top of the
unguarded, race-safe linkGuestToUser(): the guest credential AND a normalized
verified-email match. Email alone is not ownership — verification proves current
mailbox control, not that this person placed that order. Every failure raises the
same non-revealing 404 so the seam cannot be used to probe which order numbers exist,
and re-claiming your own order is a no-op success.

Claiming ships as a service, deliberately not an endpoint: Commerce cannot establish
that an email is verified — the post-auth user attribute carries no email under JWT
auth, and the users extension drops email_verified_at from its identity. The calling
application owns that context, and owns gating the email-only historical variant
behind fresh authentication, confirmation and audit. A docblock cannot enforce that;
an absent route can.

Wishlist routes join the existing auth + tenant account group, with identifiers
validated as exactly 12 alphanumeric characters rather than merely length-bounded."
```

---

## Task 7: Fold into 1.8.0 and release

**Files:**
- Modify: `CHANGELOG.md`

**The fold:** the batched catalog reads already sit under `## [Unreleased]` and have never shipped (last tag `v1.7.0`). Add these seams to that same block, then version the whole block as 1.8.0. Do **not** create a 1.9.0.

- [ ] **Step 1: Add the new entries under `[Unreleased]`**

Append to the existing `### Added` list:

```markdown
- Account-backed wishlist: `commerce_wishlists` + `commerce_wishlist_items` (migration 020),
  `WishlistService`, and `GET/POST/DELETE /commerce/account/wishlist` with
  `POST /commerce/account/wishlist/import`. A parent list row per (tenant, user) carries the
  revision claim every growth path takes, so the 100-item cap holds under concurrent saves and
  imports instead of being a count-then-insert race. Display order is an explicit `position`
  (saves take the front, imports append to the back), not a timestamp — sorting by `created_at`
  would put freshly imported rows ahead of the account's own older items. Availability is
  checked before a product can consume a slot and re-checked on every read, so an inactive
  product leaves the list without deleting the saved row. The cap is refused explicitly rather
  than evicting a deliberately saved item, and import reports imported/unavailable/overflow
  separately so the caller decides what to clear locally. Both tables join the tenancy inventory.
- `GuestOrderClaimService`: customer-safe claiming over the existing race-safe
  `OrderRepository::linkGuestToUser()`. `claim()` requires BOTH the guest credential and a
  normalized verified-email match; every failure returns the same non-revealing 404, and
  re-claiming an order you already own is a no-op success. `claimAllByVerifiedEmail()` is a
  deliberately weaker, bounded historical import for hosts to expose as an explicit, confirmed,
  audited action. Shipped as a service seam with **no route**: Commerce cannot establish that an
  email is verified, so the calling application — which owns the verified-account context —
  invokes it server-side.
```

- [ ] **Step 2: Version the block as 1.8.0**

Leave an empty `## [Unreleased]` at the top and add:

```markdown
## [1.8.0] - 2026-07-29 — Storefront Card Reads & Account Seams

Additive throughout: two new tables, four new storefront routes inside the existing `auth` +
tenant account group, one new service seam, and four batched catalog reads. No changes to
existing endpoints, no default changes, no dependency changes. Installs that use none of the new
surfaces behave exactly as before.
```

- [ ] **Step 3: Confirm there is no version constant to bump**

Run: `grep -rn "1\.7\.0" --include=*.php --include=*.json . | grep -v vendor | grep -v CHANGELOG`
Expected: no match. Commerce carries its version in git tags only.

- [ ] **Step 4: Full gates**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 src tests
```

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md
git commit -m "Release 1.8.0 — Storefront Card Reads & Account Seams

Folds the account seams into the still-unpublished 1.8.0 rather than stacking a second
release on top: the batched catalog reads had not shipped (last tag v1.7.0), so one
version carries the card reads, the wishlist and the guest-order claim seam.

Additive throughout — two new tables, four new routes in the existing auth + tenant
account group, no changes to existing endpoints."
```

- [ ] **Step 6: Hand off the tag**

Do **not** create or push the tag. Report that 1.8.0 is ready to tag and release, and that the
consuming application's pins (`^1.7.0` → `^1.8.0` at both the root and the commerce integration
pack) move only after it is published.

---

## Post-release: what unblocks

Publishing 1.8.0 unblocks Plan 4 (the commerce account area) and Plan 5 (wishlist
synchronization), and clears the outstanding storefront-v1 pin bump waiting on this release.
Plan 4 owns the guarded, audited HTTP endpoints that call the claim seams — including the
fresh-authentication and confirmation gate the historical import requires.
