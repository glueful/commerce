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
    ],

    'tenancy' => [
        'enabled' => (bool) env('COMMERCE_TENANCY_ENABLED', false),
    ],

    // Null-tolerant: invoice-data serializes each key as null, never omitted, when unset.
    'seller' => [
        'name' => env('COMMERCE_SELLER_NAME'),
        'address' => env('COMMERCE_SELLER_ADDRESS'),
        'tax_id' => env('COMMERCE_SELLER_TAX_ID'),
    ],

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
