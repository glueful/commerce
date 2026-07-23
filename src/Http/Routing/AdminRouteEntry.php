<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Routing;

/**
 * One admin endpoint in the mountable catalog (spec §3.1). Every field is explicit,
 * declared data — mode/kind/domain are never inferred from the HTTP method or controller.
 */
final class AdminRouteEntry
{
    public function __construct(
        public readonly string $key,          // stable id, e.g. 'products.index'
        public readonly string $method,       // 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
        public readonly string $path,         // prefix-relative, '/'-prefixed
        public readonly string $controller,   // Admin controller FQCN (unchanged classes)
        public readonly string $action,       // controller method
        public readonly string $mode,         // 'view' | 'manage' — authorization mode
        public readonly string $kind,         // 'json' | 'bulk' | 'binary' | 'unusual'
        public readonly string $domain,       // allowlist/phasing granularity
    ) {
    }
}
