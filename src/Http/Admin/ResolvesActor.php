<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Auth\UserIdentity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the acting principal's uuid from the framework-guaranteed `auth.user`
 * request attribute. `AuthMiddleware` synthesises a basic `UserIdentity` from the
 * authenticated user data whenever the richer permission enricher hasn't already set
 * one, so `auth.user` is populated after any successful `auth` middleware pass —
 * unlike the legacy raw `'user'` array attribute, this never needs a dual-read
 * fallback (framework >=1.65.3).
 */
trait ResolvesActor
{
    private function actorUuid(Request $request): ?string
    {
        $identity = $request->attributes->get('auth.user');

        return $identity instanceof UserIdentity ? $identity->uuid() : null;
    }
}
