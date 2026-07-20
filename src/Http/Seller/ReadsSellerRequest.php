<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shared request-reading helpers for the seller-scoped HTTP surface (MV1
 * Task 4). `principalUuid()` reads the post-auth `user` request attribute's
 * `uuid` -- the SAME house rule {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}
 * uses (never `auth.user`, which is enrichment-optional) -- so actor
 * attribution on seller-scoped writes always matches the principal the
 * middleware actually authorized.
 */
trait ReadsSellerRequest
{
    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $content = $request->getContent();
        $json = is_string($content) && $content !== '' ? json_decode($content, true) : [];
        if (!is_array($json)) {
            $json = [];
        }

        return array_merge($request->request->all(), $json);
    }

    private function principalUuid(Request $request): string
    {
        $user = $request->attributes->get('user');

        return is_array($user) ? trim((string) ($user['uuid'] ?? '')) : '';
    }
}
