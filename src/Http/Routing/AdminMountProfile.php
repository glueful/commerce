<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Routing;

/**
 * How a host mounts the admin catalog (spec §3.2). Structurally fail-closed:
 * only {@see self::native()} can express complete-catalog coverage — a restricted
 * mount MUST pass a non-empty explicit key allowlist, so a newly added Commerce
 * endpoint stays unmounted in an embedding host until consciously approved.
 */
final class AdminMountProfile
{
    /**
     * @param list<string> $middleware base stack, host-owned, ordered
     * @param array{view: string, manage: string} $modeMiddleware per-mode permission middleware
     * @param list<string>|null $allowlist null ONLY via native() (complete catalog)
     */
    private function __construct(
        public readonly string $prefix,
        public readonly string $routeNamePrefix,
        public readonly array $middleware,
        public readonly array $modeMiddleware,
        public readonly ?array $allowlist,
    ) {
    }

    /**
     * Commerce's own mount: the complete catalog at its native location, with
     * UNNAMED routes (legacy byte-parity — the pre-catalog routes carried no names).
     *
     * @param list<string> $middleware
     * @param array{view: string, manage: string} $modeMiddleware
     */
    public static function native(string $prefix, array $middleware, array $modeMiddleware): self
    {
        return new self($prefix, '', $middleware, $modeMiddleware, null);
    }

    /**
     * An embedding host's mount: explicit, non-empty key-level allowlist and a
     * route-name prefix guaranteeing name uniqueness across mounts.
     *
     * @param list<string> $middleware
     * @param array{view: string, manage: string} $modeMiddleware
     * @param list<string> $allowlist runtime-guarded non-empty below
     */
    public static function restricted(
        string $prefix,
        string $routeNamePrefix,
        array $middleware,
        array $modeMiddleware,
        array $allowlist,
    ): self {
        if ($allowlist === []) {
            throw new \InvalidArgumentException(
                'A restricted admin mount requires a non-empty endpoint allowlist; '
                . 'only AdminMountProfile::native() may mount the complete catalog.',
            );
        }
        if ($routeNamePrefix === '') {
            throw new \InvalidArgumentException('A restricted admin mount requires a route-name prefix.');
        }

        return new self($prefix, $routeNamePrefix, $middleware, $modeMiddleware, array_values($allowlist));
    }
}
