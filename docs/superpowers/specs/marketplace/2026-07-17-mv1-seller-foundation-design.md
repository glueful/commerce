# Marketplace MV1 — Optional Mode & Seller Foundation (Detail Design)

**Status:** revision 2 — for review
**Parent:** `2026-07-16-multi-vendor-overview-design.md` (this folder) — delivers slice **MV1**
**Repo scope:** commerce only. Commerce is PUBLISHED (1.1.0): every schema change here is a
REAL new migration — the fold posture does not apply.

## 1. Scope

The foundation the money slices build on, shippable alone with zero behavior change while
off: the two-level marketplace switch, seller identity + lifecycle, seller memberships with
a fixed role vocabulary behind an authority seam, catalog ownership with guarded adoption
and transfer, seller-scoped catalog/inventory APIs, platform admin surfaces, diagnostics.

**Explicitly NOT in MV1** (each is a later slice with its own spec): shared-checkout seller
grouping, seller order partitions, order-line seller snapshots, commissions, the ledger,
payouts, seller-visible orders/refunds/reports, storefront seller exposure. With MV1
enabled, checkout/orders behave exactly as today — sellers exist and own catalog, nothing
financial references them yet.

All overview invariants (§10, 1–15) bind; MV1 must not make any of them unsatisfiable.

## 2. Pinned decisions (review these first)

1. **Two-level optionality.**
   - **Install master switch:** `commerce.marketplace.enabled` (env
     `COMMERCE_MARKETPLACE_ENABLED`, default `false`) in `config/commerce.php`. When OFF,
     the marketplace route groups are NOT registered, marketplace services stay inert, and
     ordinary Commerce request paths never read marketplace tables — the existing API behavior
     remains byte-identical (the regression gate). Migrations, diagnostics, and tenant-adoption
     maintenance are explicit exceptions: they must continue to know these tables exist so data
     created before a switch-off remains coherent when the feature is enabled again. Mirrors the
     `commerce.tenancy.enabled` runtime posture.
   - **Per-workspace activation:** with the master switch on, each workspace additionally
     activates marketplace mode explicitly (a `commerce_marketplace_settings` row). A
     multi-workspace install can run malls/stores and ONE marketplace side by side.
     Single-store installs activate the sentinel (`''`) workspace directly.
2. **Activation is guarded by catalog adoption (overview Q13).** A workspace cannot
   activate while any non-deleted product lacks a seller. The activation call takes an
   optional `default_seller_uuid`; when provided, all unassigned products are bulk-adopted
   by that seller inside the activation transaction (claiming each product's
   `catalog_revision`); without it, activation 409s listing the unassigned count. Products
   created while marketplace mode is off have no seller — RE-activation re-runs the same
   adoption gate. When the install master switch is ON, activation, re-activation, and EVERY
   product-create path share the per-workspace coordination lock defined in §4. The config-OFF
   branch returns to the existing create path before any marketplace table is read. With the
   switch ON, the lock is acquired inside the product-insert transaction and before reading mode
   or catalog state, so a create commits first and is seen by activation, or activation commits
   first and the create must supply seller attribution. No unassigned product can slip between
   the adoption scan and the ACTIVE transition.
3. **Deactivation is fail-closed and non-destructive.** Deactivating hides nothing and
   deletes nothing: seller rows, memberships, and `products.seller_uuid` are retained;
   every SELLER-MEMBER operational surface responds 409 "marketplace mode is not active";
   ordinary commerce behavior is untouched (it never depended on MV1). Operator foundation
   surfaces remain available while inactive so a workspace can create sellers, establish
   memberships, adopt/transfer products, repair configuration, and satisfy the activation gate.
   Re-activation restores seller-member access. This distinction also makes first activation
   possible: configuration precedes activation.
4. **Seller lifecycle:** `onboarding | active | suspended | closed` per the overview.
   V1 transitions: create (→ `active`; `onboarding` is reserved for MV4's payout
   readiness and unused in MV1), `suspend` ⇄ `reactivate`, `close`. **Close is blocked
   while the seller owns any non-deleted product** (overview Q14: transfer or soft-delete
   them first). Once that guard passes, close atomically marks the seller `closed` and
   deactivates all of its memberships. The last-owner invariant applies while a seller is
   `onboarding|active|suspended`; closure is the sole terminal transition allowed to retire the
   final owner, so "zero active memberships before close" is NOT a precondition. Suspended
   sellers fail closed for ALL seller-member mutations (reads stay, so staff can see state);
   their products remain purchasable in MV1 (suspension gains checkout consequences only in
   MV2 — noted so nobody reads MV1 suspension as a sales stop).
5. **Seller users (overview Q7 lean).** A seller user is an ordinary authenticated
   principal (the post-auth `user` request attribute) holding an ACTIVE
   `commerce_seller_memberships` row. Commerce does NOT query host-app workspace membership
   itself. When `commerce.tenancy.enabled=true`, seller routes preserve the existing
   `auth → tenant → commerce_seller` order: the host tenancy middleware proves active workspace
   membership or an explicit bypass before Commerce evaluates seller membership. In single-store
   sentinel mode there is no workspace-membership requirement, so the order is
   `auth → commerce_seller`. Membership creation is operator/seller-owner action (invite-only,
   overview Q10); self-service onboarding is out of scope.
6. **Roles (overview Q12): fixed, code-defined vocabulary behind a seam.**
   `SellerRoleAuthority` (commerce-local interface) with a shipped
   `FixedSellerRoleAuthority`: `seller_owner | seller_admin | seller_staff | seller_analyst`
   and a policy-as-code capability matrix (the Thallo lesson: vocabulary in code, decisions
   in data — but NO per-seller overrides/custom roles in v1). V1 capabilities:

   | capability | owner | admin | staff | analyst |
   |---|---|---|---|---|
   | `commerce.seller.catalog.read` | ✓ | ✓ | ✓ | ✓ |
   | `commerce.seller.catalog.write` | ✓ | ✓ | — | — |
   | `commerce.seller.inventory.read` | ✓ | ✓ | ✓ | ✓ |
   | `commerce.seller.inventory.write` | ✓ | ✓ | ✓ | — |
   | `commerce.seller.members.manage` | ✓ | — | — | — |

   (orders/refunds/reports/payouts capabilities arrive with their slices.) Every non-closed
   seller must always retain at least one active `seller_owner` membership — the anti-lockout
   guard, same shape as the workspace last-owner rule. Seller creation requires an
   `owner_user_uuid` and writes the seller plus its first active owner membership atomically;
   an ownerless seller is never externally visible.
7. **Catalog ownership.** `commerce_products.seller_uuid` (nullable, new ALTER migration).
   Ordinary product create/update DTOs NEVER accept `seller_uuid`: creation through the
   seller-scoped API derives the seller from the authorization context; creation through
   the plain admin API while a workspace is ACTIVE requires an explicit
   `POST /…/sellers/{uuid}/products`-style attribution (a plain `POST /products` in an
   activated workspace 422s — invariant 2 stays satisfiable). The policy lives in the shared
   catalog service, not only the HTTP controller, so importer, CLI, and future internal callers
   cannot bypass it. **Adoption/transfer** is a dedicated guarded operator operation, never an
   unrestricted patch: it assigns an unowned product or moves an owned one to the target seller,
   which makes the inactive-mode repair surface promised in decision 3 concrete. It follows the
   global lock order in §4, validates the target seller active and in-tenant after locking, then
   updates the claimed product and emits the appropriate adoption/transfer audit event. Variants,
   stock,
   downloads, media, add-ons and taxonomy links inherit the seller strictly through the product
   root.
8. **Platform vs seller surfaces.** Platform marketplace administration rides the existing
   `commerce:read`/`commerce:write` API scopes (the audited workspace bypass). Operator
   foundation routes are available while the workspace is inactive; seller-member routes require
   ACTIVE mode. Seller surfaces use the new `commerce_seller` middleware after the existing
   `auth` and optional `tenant` middleware: resolve the seller from the ROUTE
   RESOURCE (never a body field), load the caller's membership, check the capability via
   the authority, fail closed (unknown seller, no membership, inactive membership,
   suspended/closed seller, workspace not activated → non-revealing 404/409 per table
   below). Cross-seller access through a seller path is structurally impossible: every
   query predicates on `tenant_uuid` AND the authorized `seller_uuid`.
9. **No storefront changes.** Public projections (already allowlisted) gain NOTHING in
   MV1. Seller attribution reaches customers only in MV2 via a deliberate allowlist
   addition. A test pins `seller_uuid` absent from every public payload.
10. **Docs discipline:** all marketplace specs/plans live under
    `docs/superpowers/specs/marketplace/` and `docs/superpowers/plans/marketplace/`.

## 3. Schema (two new migrations)

### `010_CreateMarketplaceSellerTables.php`

- `commerce_marketplace_settings` — `id`, `uuid`(12), `tenant_uuid`(12, unique), `status`
  (`active|disabled`), `default_seller_uuid`(12, nullable — audit of the adoption choice),
  `activated_by`(12, nullable), `activated_at`, `revision` int default 0 (the stable
  per-workspace coordination claim), `created_at`, `updated_at`. With the install switch on,
  the lock helper idempotently creates a disabled row before its first claim; concurrent first
  creates resolve the tenant-unique conflict by re-reading and retrying the claim.
- `commerce_sellers` — `id`, `uuid`(12, unique), `tenant_uuid`(12), `slug`(64), `name`(160),
  `metadata` json nullable (display/legal presentation only — NO bank/identity/provider
  secrets), `status`(16, default `active`), `revision` int default 0 (house claim column),
  timestamps; unique `(tenant_uuid, slug)`; index `tenant_uuid`.
- `commerce_seller_memberships` — `id`, `uuid`(12, unique), `tenant_uuid`(12),
  `seller_uuid`(12), `user_uuid`(12), `role`(32), `status`(16, default `active`),
  `created_by`(12, nullable), timestamps; unique `(seller_uuid, user_uuid)`; index
  `(tenant_uuid, user_uuid)` (the "my sellers" lookup); index
  `(seller_uuid, status, role)` (membership list + last-owner check).

### `011_AddSellerToProducts.php`

- `commerce_products` + `seller_uuid`(12, nullable) + index `(tenant_uuid, seller_uuid)`.

Payout/commission columns are deliberately ABSENT — MV3/MV4 add their own migrations.
`DiagnosticsReport`: all three tables into `commerceTables`; settings/sellers/memberships
into `tenantTables` (memberships carry `tenant_uuid` directly so the adopt CLI rekeys them).
These maintenance inventories remain complete regardless of the master switch; runtime feature
gating must not strand sentinel rows created before a later switch-off.

## 4. Services & components

| Unit | Responsibility |
|---|---|
| `src/Marketplace/MarketplaceMode.php` | the gate: `installEnabled()` (config), `activeFor(tenant)` (settings row), used by middleware/services; zero reads when config-off |
| `src/Marketplace/MarketplaceWorkspaceLock.php` | idempotently ensure + revision-claim the tenant settings row inside the caller transaction; shared serialization boundary for mode transitions and every product create |
| `src/Marketplace/MarketplaceActivationService.php` | under workspace lock: activate (adoption gate + optional bulk-adopt in one txn, revision claims per product), deactivate; audit events |
| `src/Marketplace/SellerRepository.php` + `SellerService.php` | atomic create + first owner; CRUD + lifecycle (seller claim → post-claim re-read; close blocked while products remain, then closes + deactivates memberships), slug rules like shipping classes (immutable after create) |
| `src/Marketplace/SellerMembershipRepository.php` + `SellerMembershipService.php` | membership CRUD under seller claim, concurrent-safe last-owner guard, role validation via authority |
| `src/Marketplace/Contracts/SellerRoleAuthority.php` + `FixedSellerRoleAuthority.php` | the role/capability seam; apps may rebind |
| `src/Marketplace/SellerAttributionService.php` | guarded product adoption/transfer using the global workspace → sorted sellers → product lock order, post-lock target validation, stale-source rejection, and audit |
| `src/Http/Middleware/SellerMemberMiddleware.php` (`commerce_seller:<capability>`) | route-resource seller resolution + membership/capability check, fail-closed |
| `src/Http/Admin/MarketplaceAdminController.php` | settings activate/deactivate, sellers CRUD/lifecycle/transfer, membership admin (platform scope) |
| `src/Http/Seller/SellerCatalogController.php` etc. | seller-scoped product/variant/stock surfaces reusing `CatalogService`/`InventoryService` with the seller predicate added |
| routes | new groups in `routes.php`, registered ONLY when `commerce.marketplace.enabled` (the hardened `HttpDocumentationTest` must run its walk with the flag ON so these routes stay annotation-enforced) |

**Master-OFF fast path:** `MarketplaceMode::installEnabled()` is checked before any workspace
claim or marketplace query. Only an install-enabled product create enters the marketplace lock
protocol below; OFF remains byte-identical.

**Global mutation lock order:** workspace-settings claim first; then all participating seller
revision claims sorted by seller UUID; then product `catalog_revision` claims sorted by product
UUID. Activation needs workspace + the optional default seller + products (and validates that seller
after its claim); seller-attributed create needs workspace + target seller before insert; close needs
seller before counting products; membership mutations need seller before owner-count/write; transfer
needs workspace + source/target sellers + product. Adoption is the same operation with no source
seller. Transfer snapshots the current source before choosing its sorted seller claim set, claims the
product, then re-reads it; if the source changed, the transaction aborts with a stale-ownership 409
rather than acquiring a newly discovered seller after the product claim. No flow may invert this order. This makes
close-vs-create, transfer-vs-close, concurrent owner demotions, and activation-vs-create deterministic
rather than merely test-observed.

Error semantics table (fail-closed): master off → routes absent (404 by nonexistence);
workspace not activated → seller-member routes 409 while operator foundation routes remain
available; unknown seller / no membership / cross-seller → 404 (non-revealing);
suspended/closed seller-member mutation → 409; inactive membership → 404.

## 5. Flows

- **Configure while inactive:** operator (`commerce:write`) may create sellers with their first
  owner, manage memberships, and adopt/transfer products until the activation gate is satisfied.
- **Activate:** operator (`commerce:write`) → workspace claim → adoption gate (§2.2) → settings row →
  `MarketplaceActivated` audit event. **Deactivate:** settings row → `disabled`; nothing
  else changes (§2.3).
- **Seller create/suspend/reactivate/close:** platform admin; create writes its first owner in the
  same transaction; lifecycle is claim-serialized; close verifies zero products then atomically
  closes + deactivates memberships (§2.4); audit events per transition.
- **Membership grant/change/revoke:** platform admin or `seller_owner`
  (`commerce.seller.members.manage`); last-owner guard on demote/revoke; roles from the
  authority's vocabulary only.
- **Seller catalog:** seller-scoped create derives `seller_uuid` from authorization;
  reads/writes predicate tenant + seller; inventory adjustments write the existing
  movement ledger with the seller predicate enforced at the product root.
- **Adopt/transfer:** platform admin only in v1; one dedicated attribution operation handles
  unowned → seller and seller → seller per §2.7.

## 6. Tests

- **Regression gate (the "optional" proof):** with `COMMERCE_MARKETPLACE_ENABLED` absent —
  full existing behavior/API projections byte-identical; route manifest identical; ordinary
  Commerce requests execute zero marketplace-table queries. Migration, diagnostics, and
  tenant-adoption tests explicitly retain their maintenance visibility.
- Activation: adoption 409 with unassigned count; default-seller bulk adopt (revision
  bumps per product); re-activation re-gates products created while off; single-store
  sentinel activation; deterministic + pgsql two-connection activation-vs-product-create in
  both commit orderings proves no active workspace can retain a newly unassigned product.
- Lifecycle: create atomically exposes seller + first owner; close blocked by products, then
  closes + deactivates memberships atomically; suspend fail-closes seller-member mutations but
  not reads; slug immutability; deterministic + pgsql two-connection close-vs-product-create,
  transfer-vs-close, and transfer-vs-transfer tests follow the pinned lock order.
- Authorization matrix: every capability × role from §2.6; cross-seller 404
  (non-revealing); caller-supplied seller_uuid in bodies ignored/rejected; suspended
  membership fail-closed; concurrent last-owner demotions serialize; platform bypass works with
  `commerce:write`; tenancy-enabled user with a seller-membership row but no active workspace
  membership is denied by `tenant` before `commerce_seller` runs.
- Ownership: ordinary `POST /products` 422 in an activated workspace; seller-derived
  creation; inactive-mode adoption; child resources unreachable across sellers; transfer
  atomicity and stale-source rejection.
- Storefront: `seller_uuid` absent from every public payload (allowlist pin).
- Tenancy: two-tenant isolation incl. same seller slug both tenants; adopt-CLI rekeys the
  three tables even when the marketplace master switch is currently off.
- Docs: `HttpDocumentationTest` walk with the flag ON covers all new routes.

## 7. Out of scope (deferred to their slices)

Checkout/seller grouping, seller orders, line snapshots (MV2); commissions + ledger (MV3);
payouts + `extension-contracts` port + Payvia (MV4); disputes/reserves/seller API keys
(MV5); seller self-service onboarding; custom seller roles/overrides; storefront seller
display; seller suspension affecting checkout (MV2 decision).
