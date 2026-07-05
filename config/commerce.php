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

    'orders' => [
        'expiry_minutes' => (int) env('COMMERCE_ORDER_EXPIRY_MINUTES', 60),
        // {seq} placeholder, zero-padded to 6.
        'number_format' => env('COMMERCE_ORDER_NUMBER_FORMAT', 'ORD-{seq}'),
    ],

    'tenancy' => [
        'enabled' => (bool) env('COMMERCE_TENANCY_ENABLED', false),
    ],
];
