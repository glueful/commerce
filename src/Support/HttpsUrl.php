<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * The ONE definition of "absolute HTTPS URL" for every host-bound URL Commerce
 * accepts from a seam (payment-links review round 1, minor 6).
 *
 * It exists so two consumers cannot invent two strictnesses for the same words.
 * Today those consumers are
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService::paymentReturnMetadata()}
 * and -- from Task 7 -- the payment-link
 * {@see \Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider}
 * output. Both feed browser-navigation URLs into a payment provider's payable
 * metadata, so both must mean exactly the same thing by "absolute HTTPS".
 *
 * ## What it checks, and what it deliberately allows
 *
 * Scheme `https` (compared CASE-INSENSITIVELY -- RFC 3986 §3.1 makes schemes
 * case-insensitive and `parse_url()` preserves whatever case it was given, so a
 * host legitimately returning `HTTPS://...` must not be refused), and a
 * non-empty host. That is all. Note that lowercasing the PARSED scheme cannot
 * widen this to `http`: `strtolower('HTTP') !== 'https'`.
 *
 * It does NOT reject userinfo, an explicit port, a query string, or a fragment,
 * and that permissiveness is the point rather than an omission: a signed return
 * route legitimately carries its signature and its link reference in the QUERY
 * STRING (design spec §2.3), and a self-hosted install may legitimately serve
 * its canonical origin on a non-default port. Refusing those would break correct
 * hosts.
 *
 * ## Why this is NOT the payment-link PUBLIC url check
 *
 * {@see \Glueful\Extensions\Commerce\Orders\PaymentLinkService}'s
 * `isValidPublicUrl()` is deliberately far stricter -- no userinfo, no port, no
 * query, no fragment, and the raw token exactly once as the final path segment.
 * It has to be: that URL CARRIES A BEARER TOKEN, so a query string would copy
 * the credential into access logs, proxy logs, and `Referer` headers. A return
 * URL carries no such secret. The two must not be merged; loosening the public
 * check to match this one would be a security regression, and tightening this
 * one to match that one would reject valid signed return routes.
 */
final class HttpsUrl
{
    public static function isAbsoluteHttps(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== '';
    }
}
