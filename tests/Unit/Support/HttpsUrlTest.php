<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\HttpsUrl;
use PHPUnit\Framework\TestCase;

/**
 * The ONE "absolute HTTPS" definition (payment-links review round 1, minor 6).
 *
 * The accepted set matters as much as the rejected one here: a check that is
 * too strict silently breaks correct hosts (a signed return route carries its
 * signature in the query string; a self-hosted origin may use a non-default
 * port), while one that is too loose lets a `http://` or scheme-relative URL
 * reach a payment provider's callback metadata.
 */
final class HttpsUrlTest extends TestCase
{
    /** @dataProvider absoluteHttpsUrls */
    public function testAcceptsAbsoluteHttpsUrls(string $url): void
    {
        self::assertTrue(HttpsUrl::isAbsoluteHttps($url), $url);
    }

    /** @return array<string, array{string}> */
    public static function absoluteHttpsUrls(): array
    {
        return [
            'bare host' => ['https://shop.example.com'],
            'with path' => ['https://shop.example.com/pay/return'],
            'trailing slash' => ['https://shop.example.com/'],
            // Deliberately ACCEPTED: a signed return route carries its signature
            // and its link reference in the query string (design spec §2.3).
            'query string' => ['https://shop.example.com/pay/return?link=abc&sig=def'],
            'fragment' => ['https://shop.example.com/pay/return#done'],
            // Deliberately ACCEPTED: a self-hosted canonical origin may use one.
            'explicit port' => ['https://shop.example.com:8443/pay/return'],
            'userinfo' => ['https://user@shop.example.com/pay/return'],
            'subdomain and idn-ish host' => ['https://a.b.c.example.co.uk/x'],
            'ipv4 host' => ['https://203.0.113.10/return'],
            'mixed-case scheme (RFC 3986 makes schemes case-insensitive)' => ['httpS://shop.example.com/x'],
        ];
    }

    /** @dataProvider rejectedUrls */
    public function testRejectsAnythingThatIsNotAbsoluteHttps(string $url): void
    {
        self::assertFalse(HttpsUrl::isAbsoluteHttps($url), $url);
    }

    /** @return array<string, array{string}> */
    public static function rejectedUrls(): array
    {
        return [
            'empty' => [''],
            'plain http' => ['http://shop.example.com/return'],
            'scheme relative' => ['//shop.example.com/return'],
            'absolute path only' => ['/pay/return'],
            'relative' => ['pay/return'],
            'no host' => ['https:///pay/return'],
            'https with empty everything' => ['https://'],
            'ftp' => ['ftp://shop.example.com/return'],
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<b>x</b>'],
            'mailto' => ['mailto:someone@example.com'],
            'file' => ['file:///etc/passwd'],
            'whitespace' => ['   '],
            'not a url' => ['definitely not a url'],
        ];
    }

    /**
     * `parse_url()` PRESERVES scheme case, so the case-insensitive comparison is
     * ours and has to be pinned: an uppercase `HTTPS://` is a valid absolute
     * HTTPS URL and must be accepted, while normalizing the scheme must never
     * widen the check to `HTTP://`. Both directions asserted, because getting
     * only the first one right is exactly how a "case-insensitive URL check"
     * turns into an open door.
     */
    public function testSchemeComparisonIsCaseInsensitiveWithoutAdmittingHttp(): void
    {
        self::assertTrue(HttpsUrl::isAbsoluteHttps('HTTPS://shop.example.com/x'));
        self::assertFalse(HttpsUrl::isAbsoluteHttps('HTTP://shop.example.com/x'));
    }
}
