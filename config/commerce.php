<?php

declare(strict_types=1);

return [
    // Single store currency (ISO 4217). Every variant price must match it.
    'currency' => env('COMMERCE_CURRENCY', 'USD'),

    'tax' => [
        // Flat rate in basis points applied by the default TaxCalculator (0 = no tax).
        'flat_rate_bps' => (int) env('COMMERCE_TAX_BPS', 0),
    ],

    'shipping' => [
        // Each method: id, label, amount (minor units), optional free_over (minor units),
        // optional zones (ISO country allowlist; absent = everywhere).
        'methods' => [
            ['id' => 'standard', 'label' => 'Standard shipping', 'amount' => 500, 'free_over' => 5000],
        ],
    ],

    'cart' => [
        'ttl_days' => (int) env('COMMERCE_CART_TTL_DAYS', 30),
    ],

    'rate_limits' => [
        'cart' => [(int) env('COMMERCE_CART_RATE_LIMIT', 60), 60],
        'checkout' => [(int) env('COMMERCE_CHECKOUT_RATE_LIMIT', 30), 60],
        'orders' => [(int) env('COMMERCE_ORDER_RATE_LIMIT', 60), 60],
        'downloads' => [(int) env('COMMERCE_DOWNLOADS_RATE_LIMIT', 60), 60],
        // Storefront product list/show + category tree (+ approved-review list, Task 6).
        'catalog' => [(int) env('COMMERCE_CATALOG_RATE_LIMIT', 120), 60],
        // Storefront review POST -- stricter than `catalog` (Task 6 wires this to the route).
        'review_submit' => [(int) env('COMMERCE_REVIEW_SUBMIT_RATE_LIMIT', 5), 60],
    ],

    // Digital-delivery signed URLs (design spec §4.1).
    'downloads' => [
        'url_ttl' => (int) env('COMMERCE_DOWNLOADS_URL_TTL', 300),
    ],

    'orders' => [
        'expiry_minutes' => (int) env('COMMERCE_ORDER_EXPIRY_MINUTES', 60),
        // {seq} placeholder, zero-padded to 6.
        'number_format' => env('COMMERCE_ORDER_NUMBER_FORMAT', 'ORD-{seq}'),
        // Admin-order-creation cycle 2, Task 8: how long an untouched admin
        // ("walk-in") DRAFT order survives before the expiry cron cancels it.
        // Measured from the draft's LAST TOUCH (`updated_at`, falling back to
        // `created_at`), so an actively edited draft never expires under an
        // operator. Deliberately generous and independent of `expiry_minutes`:
        // that governs unpaid REAL orders holding reserved stock, this governs
        // an operator's unfinished paperwork, which holds nothing.
        'draft_ttl_days' => (int) env('COMMERCE_ORDER_DRAFT_TTL_DAYS', 30),
    ],

    // Payment links (payment-links spec §2.2).
    'payment_links' => [
        // How many checkout initiations ONE link may claim inside a FIXED UTC
        // one-hour window. Per-link, not per-order or per-tenant: what is being
        // protected is the payment provider from a single shared URL being
        // hammered, and the customer from a wall of abandoned sessions. Clamped
        // to 1..100 on read ({@see \Glueful\Extensions\Commerce\Support\CommerceSettings::paymentLinkInitiationsPerHour()})
        // -- 0 would make every link permanently unusable and an unbounded value
        // would make the ceiling meaningless, so neither is honoured.
        'initiations_per_hour' => (int) env('COMMERCE_PAYMENT_LINK_INITIATIONS_PER_HOUR', 10),
    ],

    'tenancy' => [
        'enabled' => (bool) env('COMMERCE_TENANCY_ENABLED', false),
    ],

    // Marketplace MV1 install master switch (design spec §2.1). OFF by default:
    // marketplace route groups stay unregistered, marketplace services stay
    // inert, and ordinary Commerce request paths never read a marketplace
    // table. Per-workspace activation (a `commerce_marketplace_settings` row)
    // layers on top of this once installed -- see
    // Glueful\Extensions\Commerce\Marketplace\MarketplaceMode.
    'marketplace' => [
        'enabled' => (bool) env('COMMERCE_MARKETPLACE_ENABLED', false),

        // Commission MV3, design spec §2.2/§3.6: the final fallback level in the
        // product -> seller -> workspace-settings -> config precedence chain
        // CommissionPolicyResolver walks at checkout. Unlike the three inheritable
        // database levels, this tail is TOTAL -- it must never be all-null, so
        // policy resolution always terminates. Same validated shape as the
        // database levels ({kind: percentage|fixed, bps: 0..10000, fixed: >=0}).
        'commission' => [
            'kind' => env('COMMERCE_COMMISSION_KIND', 'percentage'),
            'bps' => (int) env('COMMERCE_COMMISSION_BPS', 0),
            'fixed' => env('COMMERCE_COMMISSION_FIXED', null),
        ],

        // Provider-payout saga (MV4, design spec §2.6/§3.1/§3.5-config). Inert
        // whether or not a `PayoutCollector` is bound -- manual payouts and
        // ledger semantics never read this block.
        'payouts' => [
            // Per-currency minimum payout amount (minor units) below which
            // `run-batch` skips a candidate. Empty by default -- no minimum
            // unless configured. Only the store's single configured currency
            // is env-backed here (mirrors the file's single-currency
            // top-level `currency` key); add further currencies via an
            // app-level config override.
            'minimums' => array_filter(
                [(string) env('COMMERCE_CURRENCY', 'USD') => (int) env('COMMERCE_PAYOUTS_MIN_AMOUNT', 0)],
                static fn (int $amount): bool => $amount > 0
            ),

            // Optional per-currency maximum (minor units) capping a
            // `run-batch` amount. An absent (or zero) currency means the
            // batch uses the full locked available balance (§2.6) -- empty
            // by default, same env-backing convention as `minimums` above.
            'maximums' => array_filter(
                [(string) env('COMMERCE_CURRENCY', 'USD') => (int) env('COMMERCE_PAYOUTS_MAX_AMOUNT', 0)],
                static fn (int $amount): bool => $amount > 0
            ),

            // Retry backoff schedule driving `claimRetryableForAttempt`'s
            // `next_attempt_at` (§2.6). Seconds.
            'backoff' => [
                'base_seconds' => (int) env('COMMERCE_PAYOUTS_BACKOFF_BASE_SECONDS', 60),
                'multiplier' => (int) env('COMMERCE_PAYOUTS_BACKOFF_MULTIPLIER', 2),
                'max_seconds' => (int) env('COMMERCE_PAYOUTS_BACKOFF_MAX_SECONDS', 3600),
            ],

            // Attempts exhausted beyond this terminalize the payout and
            // release its hold rather than scheduling another retry.
            'max_attempts' => (int) env('COMMERCE_PAYOUTS_MAX_ATTEMPTS', 5),

            // Reconcile-sweep cadence (seconds) for unresolved pending/unknown
            // payouts (§2.6/§2.8).
            'pending_reconcile_interval' => (int) env('COMMERCE_PAYOUTS_PENDING_RECONCILE_INTERVAL', 300),

            // Slower reconcile-sweep cadence (seconds) for already-paid
            // payouts -- covers provider-reported reversals only (§2.8).
            'paid_reconcile_interval' => (int) env('COMMERCE_PAYOUTS_PAID_RECONCILE_INTERVAL', 86400),

            // Provider id used when a payout doesn't specify one explicitly.
            // Null keeps provider payouts unavailable regardless of binding
            // (soft `PayoutCollector`, design spec §2.9).
            'default_provider' => env('COMMERCE_PAYOUTS_DEFAULT_PROVIDER'),
        ],

        // Rolling-reserve release sweep (MV5a, design spec §2.3/§3-config).
        // Operational settings ONLY -- the reserve POLICY itself
        // (`reserve_bps`/`reserve_days`, default 0 = off) lives exclusively in
        // `commerce_marketplace_settings`/`commerce_sellers`, never here.
        'reserves' => [
            // Max `commerce_seller_reserves` rows the release-sweep CLI claims
            // per invocation.
            'release_sweep_batch_size' => (int) env('COMMERCE_RESERVES_RELEASE_SWEEP_BATCH_SIZE', 100),
        ],

        // Seller API keys (MV5c-1, design spec §2.10). Machine-credential
        // binding/authorization settings ONLY -- the key material itself
        // remains entirely framework-owned/configured (`ApiKeyService`).
        'api_keys' => [
            // Retention window (days) for `commerce_seller_api_key_events`
            // rows with action=`auth_denied` before a host-scheduled cleanup
            // command purges them. Permanent mutation events (created/
            // rotated/revoked) are never subject to this retention window.
            'auth_denied_retention_days' => (int) env('COMMERCE_API_KEYS_AUTH_DENIED_RETENTION_DAYS', 90),
        ],

        // Seller outbound webhooks (MV5c-2, design spec §2.4/§2.6/§2.7/§2.9/
        // §3-config). Delivery/retry/claim/retention tuning ONLY -- signing
        // and SSRF-safe delivery themselves remain entirely framework-owned
        // (`WebhookSignature`, `SafeOutboundTargetResolver`/
        // `Client::safeWebhookRequestAsync()`).
        'webhooks' => [
            // Bounded exponential-backoff retry budget (design spec §2.7):
            // attempts exhausted beyond this move a retryable delivery to
            // dead_letter.
            'max_attempts' => (int) env('COMMERCE_WEBHOOKS_MAX_ATTEMPTS', 10),
            'backoff_base_seconds' => (int) env('COMMERCE_WEBHOOKS_BACKOFF_BASE_SECONDS', 30),
            'backoff_cap_seconds' => (int) env('COMMERCE_WEBHOOKS_BACKOFF_CAP_SECONDS', 3600),
            // Fractional jitter (0..1) applied to each computed backoff delay.
            'jitter' => (float) env('COMMERCE_WEBHOOKS_JITTER', 0.2),
            // Per-endpoint consecutive-failure threshold that flips it to
            // `disabled` and pauses its other pending work (design spec §2.7).
            'consecutive_failure_disable_threshold' => (int) env(
                'COMMERCE_WEBHOOKS_CONSECUTIVE_FAILURE_THRESHOLD',
                20
            ),
            // Rotation overlap window (design spec §2.2): how long a retired
            // `current` secret remains valid as `previous` after rotation.
            'secret_overlap_hours' => (int) env('COMMERCE_WEBHOOKS_SECRET_OVERLAP_HOURS', 24),
            // Per-attempt HTTP connect/read budget (design spec §2.6), seconds.
            'delivery_timeout_seconds' => (int) env('COMMERCE_WEBHOOKS_DELIVERY_TIMEOUT_SECONDS', 10),
            // Crash-safe claim lease (design spec §2.7), seconds. MUST stay
            // strictly greater than `delivery_timeout_seconds` -- otherwise a
            // healthy in-flight HTTP attempt could be reclaimed by the sweep
            // out from under itself before it can even time out on its own.
            // The default (30 > 10) satisfies this; a service that reads this
            // config (a later task) must validate the invariant at boot/use.
            'claim_lease_seconds' => (int) env('COMMERCE_WEBHOOKS_CLAIM_LEASE_SECONDS', 30),
            // Strict response-size cap (design spec §2.6), bytes.
            'max_response_bytes' => (int) env('COMMERCE_WEBHOOKS_MAX_RESPONSE_BYTES', 65536),
            // Retention window (days) for terminal delivery history
            // (delivered/dead_letter/canceled) and superseded secrets before a
            // host-scheduled cleanup command purges them.
            'retention_days' => (int) env('COMMERCE_WEBHOOKS_RETENTION_DAYS', 90),
            // Max due rows the recovery-sweep CLI
            // (`commerce:marketplace:webhooks:sweep`) claims per invocation
            // (design spec §2.4).
            'sweep_batch_size' => (int) env('COMMERCE_WEBHOOKS_SWEEP_BATCH_SIZE', 100),
        ],
    ],

    // Null-tolerant: invoice-data serializes each key as null, never omitted, when unset.
    'seller' => [
        'name' => env('COMMERCE_SELLER_NAME'),
        'address' => env('COMMERCE_SELLER_ADDRESS'),
        'tax_id' => env('COMMERCE_SELLER_TAX_ID'),
    ],

    // Admin-origin order-confirmation toggle (admin-order-creation cycle 2,
    // Task 10; design spec §2.5.9). Governs the "we received your order" mail for
    // orders whose `origin` is `admin` — a walk-in sale is handed over at the
    // counter, so a merchant may want no placement mail for those while keeping
    // every payment/fulfilment mail. ON by default (no behavior change for anyone
    // who never sets it) and consulted ONLY for admin-origin orders: storefront
    // OrderPlaced handling is byte-identical either way. Keyed at the config root
    // because settings keys EQUAL config keys (store-settings spec §3.2) and this
    // is the runtime-editable `commerce.order_confirmation` toggle a host's
    // settings screen exposes.
    'order_confirmation' => (bool) env('COMMERCE_ORDER_CONFIRMATION', true),

    'email' => [
        // Master switch: OFF by default. Operators opt in after configuring an email
        // channel and reviewing seller/template data — this preserves existing-install
        // behavior even when an email channel is already present.
        'enabled' => (bool) env('COMMERCE_EMAIL_ENABLED', false),
        'templates' => [
            'order_placed' => true,
            'order_paid' => true,
            'order_fulfilled' => true,
            'order_refunded' => true,
            'order_note' => true,
        ],
    ],

    'reports' => [
        // Woo-parity default (Layer 5 design spec decision 10); 0..100000, validated by
        // Glueful\Extensions\Commerce\Reports\StockThreshold.
        'low_stock_threshold' => (int) env('COMMERCE_REPORTS_LOW_STOCK_THRESHOLD', 2),
    ],
];
